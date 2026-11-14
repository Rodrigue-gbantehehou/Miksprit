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
                $this->recordOperatorSettlement($connection, $tenantId->value, $order, $status);
            } elseif ($attempt->state() === PaymentState::Failed && $order->state() === OrderState::PendingPayment) {
                $order->markPaymentFailed($attempt);
            }

            // wrapInTransaction flushes both managed aggregates and rolls them back together on any mismatch.
        });
    }

    private function recordOperatorSettlement(\Doctrine\DBAL\Connection $connection, string $tenantId, WifiOrder $order, VerifiedPaymentStatus $status): void
    {
        $bps = $order->platformCommissionBps;
        if ($bps === null) {
            return; // Historical orders predate the platform commission policy.
        }
        $settlement = $connection->fetchAssociative(
            'SELECT id, state FROM operator_settlements WHERE tenant_id = ? AND order_reference = ? FOR UPDATE',
            [$tenantId, $order->publicReference],
        );
        if (is_array($settlement) && $settlement['state'] !== 'awaiting_settlement') {
            return; // Never rewrite or re-credit a settled or manually flagged sale.
        }
        $id = is_array($settlement) ? (string) $settlement['id'] : 'settlement_'.substr(hash('sha256', $tenantId."\0".$order->publicReference), 0, 40);
        $gross = $order->plan->price->minorUnits;
        $now = $status->observedAt->format('Y-m-d H:i:s');
        if (!is_array($settlement)) {
            $connection->insert('operator_settlements', [
                'id' => $id, 'tenant_id' => $tenantId, 'order_reference' => $order->publicReference,
                'gross_minor_units' => $gross, 'provider_fee_minor_units' => null,
                'amount_transferred_minor_units' => null, 'commission_bps' => $bps,
                'commission_minor_units' => null, 'operator_net_minor_units' => null,
                'state' => 'awaiting_settlement', 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        if (!$status->settled || $status->providerFeeMinorUnits === null || $status->amountTransferredMinorUnits === null) {
            return;
        }
        $commission = intdiv($gross * $bps, 10_000);
        // FedaPay amount_transferred is retained as evidence; compute payable from gross less actual fee and agreed commission.
        $net = $gross - $status->providerFeeMinorUnits - $commission;
        if ($net < 0 || $status->amountTransferredMinorUnits > $gross || $status->providerFeeMinorUnits > $gross
            || $net > $status->amountTransferredMinorUnits) {
            // Keep Wi-Fi fulfillment independent from a money reconciliation issue.
            // No operator credit is issued until the provider amounts are reviewed.
            $connection->update('operator_settlements', [
                'provider_fee_minor_units' => $status->providerFeeMinorUnits,
                'amount_transferred_minor_units' => $status->amountTransferredMinorUnits,
                'commission_minor_units' => $commission,
                'state' => 'needs_review',
                'updated_at' => $now,
            ], ['id' => $id]);
            return;
        }
        $connection->update('operator_settlements', [
            'provider_fee_minor_units' => $status->providerFeeMinorUnits,
            'amount_transferred_minor_units' => $status->amountTransferredMinorUnits,
            'commission_minor_units' => $commission,
            'operator_net_minor_units' => $net,
            'state' => 'available',
            'updated_at' => $now,
        ], ['id' => $id]);
        $connection->executeStatement(
            'INSERT IGNORE INTO operator_wallet_entries (id, tenant_id, source_type, source_reference, source_reference_hash, entry_type, amount_delta_minor, currency, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            ['wallet_'.substr(hash('sha256', $tenantId."\0".$order->publicReference."\0sale_credit"), 0, 40), $tenantId,
                'wifi_order', $order->publicReference, hash('sha256', $order->publicReference),
                'sale_credit', $net, 'XOF', $now],
        );
    }

    private function lockSuffix(Connection $connection): string
    {
        $platform = $connection->getDatabasePlatform();

        return $platform instanceof AbstractMySQLPlatform || $platform instanceof PostgreSQLPlatform
            ? ' FOR UPDATE'
            : '';
    }
}
