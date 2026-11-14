<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\OrderState;
use App\Entity\PaymentAttempt;
use App\Entity\PaymentOutboxMessage;
use App\Entity\PaymentOutboxMessageState;
use App\Entity\PaymentOutboxMessageType;
use App\Entity\PaymentState;
use App\Entity\TenantId;
use App\Entity\VerifiedPaymentStatus;
use App\Entity\WifiOrder;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/** Applies only server-verified PSP evidence; attempt and order transitions share one SQL transaction. */
#[AsAlias(PaymentConfirmationStore::class)]
final readonly class DoctrinePaymentConfirmationStore implements PaymentConfirmationStore
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function apply(VerifiedPaymentStatus $status): void
    {
        $this->entityManager->wrapInTransaction(function (EntityManagerInterface $entityManager) use ($status): void {
            $connection = $entityManager->getConnection();
            $lockSuffix = $this->lockSuffix($connection);

            // The provider reference is globally unique per provider; never trust an order or tenant ID from a webhook.
            $attemptRow = $connection->fetchAssociative(
                'SELECT id, tenant_id FROM payment_attempts WHERE provider = ? AND provider_reference = ?'.$lockSuffix,
                [$status->provider, $status->providerReference],
            );
            if ($attemptRow === false) {
                throw new \DomainException('Payment attempt not found.');
            }

            $attempt = $entityManager->find(PaymentAttempt::class, $attemptRow['id']);
            if (!$attempt instanceof PaymentAttempt) {
                throw new \DomainException('Payment attempt not found.');
            }
            $tenantId = new TenantId((string) $attemptRow['tenant_id']);

            $orderRow = $connection->fetchAssociative(
                'SELECT id FROM wifi_orders WHERE tenant_id = ? AND public_reference = ?'.$lockSuffix,
                [$tenantId->value, $status->merchantReference],
            );
            if ($orderRow === false) {
                throw new \DomainException('Payment order not found.');
            }

            $order = $entityManager->find(WifiOrder::class, $orderRow['id']);
            if (!$order instanceof WifiOrder || !$order->tenantId()->equals($tenantId)) {
                throw new \DomainException('Payment order not found.');
            }

            // The aggregate validates transaction reference, order reference, XOF amount, and currency.
            $attempt->applyVerifiedStatus($status);
            if ($attempt->state() === PaymentState::Succeeded) {
                $firstConfirmation = $order->confirmPayment($attempt);
                if ($firstConfirmation) {
                    $order->markAccessPending();
                    $outboxId = 'outbox_'.substr(hash('sha256', $tenantId->value."\0".$order->publicReference."\0provision_paid_order"), 0, 40);
                    $entityManager->persist(new PaymentOutboxMessage(
                        $outboxId,
                        $tenantId,
                        $order->publicReference,
                        PaymentOutboxMessageType::ProvisionPaidOrder,
                        'payment-confirmed:'.$tenantId->value.':'.$order->publicReference,
                        PaymentOutboxMessageState::Pending,
                        $status->observedAt,
                    ));
                }
            } elseif ($attempt->state() === PaymentState::Failed && $order->state() === OrderState::PendingPayment) {
                $order->markPaymentFailed($attempt);
            }

            // wrapInTransaction flushes both managed aggregates and rolls them back together on any mismatch.
        });
    }

    private function lockSuffix(Connection $connection): string
    {
        $platform = $connection->getDatabasePlatform();

        return $platform instanceof AbstractMySQLPlatform || $platform instanceof PostgreSQLPlatform
            ? ' FOR UPDATE'
            : '';
    }
}
