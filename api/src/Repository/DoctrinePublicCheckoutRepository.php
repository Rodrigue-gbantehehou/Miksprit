<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PaymentAttempt;
use App\Entity\PlanSnapshot;
use App\Entity\TenantId;
use App\Entity\WifiOrder;
use App\Entity\XofAmount;
use App\Service\Subscription\TenantSubscriptionAccess;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(PublicCheckoutRepository::class)]
final readonly class DoctrinePublicCheckoutRepository implements PublicCheckoutRepository
{
    public function __construct(private Connection $connection, private EntityManagerInterface $entities, private TenantSubscriptionAccess $subscriptionAccess)
    {
    }

    public function findPurchaseablePlan(string $siteId, string $planId): ?PlanSnapshot
    {
        if (preg_match('/^[A-Za-z0-9_-]{8,80}$/D', $siteId) !== 1 || preg_match('/^[A-Za-z0-9_-]{8,80}$/D', $planId) !== 1) return null;
        $row = $this->connection->fetchAssociative(
            'SELECT p.id, p.site_id, p.tenant_id, p.version, p.name, p.price_minor_units, p.session_seconds,
                    p.data_limit_bytes, p.device_limit
             FROM access_plans p
             INNER JOIN sites s ON s.id = p.site_id AND s.tenant_id = p.tenant_id
             INNER JOIN organizations o ON o.tenant_id = s.tenant_id
             WHERE p.id = :planId AND p.site_id = :siteId AND p.publication_state = :published
               AND s.status = :siteActive AND o.organization_status = :orgActive
               AND (SELECT COUNT(*) FROM routers r WHERE r.tenant_id = s.tenant_id AND r.site_id = s.id AND r.status = :routerActive) = 1',
            ['planId' => $planId, 'siteId' => $siteId, 'published' => 'published', 'siteActive' => 'active', 'orgActive' => 'active', 'routerActive' => 'active'],
        );
        if ($row === false) return null;
        if (!$this->subscriptionAccess->mayOperate(new TenantId((string) $row['tenant_id']))) return null;
        return new PlanSnapshot((string) $row['id'], (string) $row['site_id'], new TenantId((string) $row['tenant_id']),
            (int) $row['version'], (string) $row['name'], new XofAmount((int) $row['price_minor_units']),
            (int) $row['session_seconds'], $row['data_limit_bytes'] === null ? null : (int) $row['data_limit_bytes'], (int) $row['device_limit']);
    }

    public function findAttemptByCheckoutKeyHash(string $keyHash): ?PaymentAttempt
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $keyHash) !== 1) return null;
        $attempt = $this->entities->createQueryBuilder()->select('attempt')->from(PaymentAttempt::class, 'attempt')
            ->where('attempt.checkoutRequestKeyHash = :keyHash')->setParameter('keyHash', $keyHash)->getQuery()->getOneOrNullResult();
        return $attempt instanceof PaymentAttempt ? $attempt : null;
    }

    public function saveOrderAndAttempt(WifiOrder $order, PaymentAttempt $attempt): void
    {
        if (!$order->tenantId()->equals($attempt->tenantId) || $attempt->orderReference !== $order->publicReference
            || !$attempt->expectedAmount->equals($order->plan->price)) {
            throw new \DomainException('Checkout order and payment attempt do not match.');
        }
        $this->entities->persist($order);
        $this->entities->persist($attempt);
        $this->entities->flush();
    }

    public function saveAttempt(PaymentAttempt $attempt): void
    {
        if (!$this->entities->contains($attempt)) throw new \LogicException('A payment attempt must be persisted before it is updated.');
        $this->entities->flush();
    }

    public function findPublicOrderStatus(string $reference): ?array
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $reference) !== 1) return null;
        $row = $this->connection->fetchAssociative(
            'SELECT o.public_reference, o.tenant_id, o.state AS order_state, p.state AS payment_state,
                    v.id AS voucher_id, v.status AS voucher_status, v.encrypted_credential
             FROM wifi_orders o INNER JOIN payment_attempts p ON p.tenant_id = o.tenant_id AND p.order_reference = o.public_reference
             LEFT JOIN vouchers v ON v.tenant_id = o.tenant_id AND v.order_reference = o.public_reference
             WHERE o.public_reference = :reference LIMIT 1',
            ['reference' => $reference],
        );
        if ($row === false) return null;
        return ['reference' => (string) $row['public_reference'], 'paymentStatus' => (string) $row['payment_state'], 'orderStatus' => (string) $row['order_state'],
            'tenantId' => (string) $row['tenant_id'], 'voucherId' => $row['voucher_id'] === null ? null : (string) $row['voucher_id'],
            'voucherStatus' => $row['voucher_status'] === null ? null : (string) $row['voucher_status'],
            'encryptedCredential' => $row['encrypted_credential'] === null ? null : (string) $row['encrypted_credential']];
    }
}
