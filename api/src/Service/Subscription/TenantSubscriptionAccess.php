<?php

declare(strict_types=1);

namespace App\Service\Subscription;

use App\Entity\TenantId;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Existing workspaces remain usable until subscription billing is configured; once enrolled, writes/sales require valid paid time. */
final readonly class TenantSubscriptionAccess
{
    public function __construct(
        private Connection $connection,
        #[Autowire('%env(bool:default:platform_subscriptions_not_required:PLATFORM_SUBSCRIPTIONS_REQUIRED)%')]
        private bool $subscriptionsRequired = false,
    ) {}

    public function mayOperate(TenantId $tenantId, ?\DateTimeImmutable $now = null): bool
    {
        $row = $this->connection->fetchAssociative(
            'SELECT status, current_period_end, grace_days_snapshot FROM platform_subscriptions WHERE tenant_id = ?',
            [$tenantId->value],
        );
        if ($row === false) return !$this->subscriptionsRequired;
        if ($row['status'] !== 'active' && $row['status'] !== 'past_due') return false;
        if ($row['current_period_end'] === null) return false;
        $now ??= new \DateTimeImmutable();
        $end = new \DateTimeImmutable((string) $row['current_period_end']);
        return $end > $now || $end->add(new \DateInterval('P'.(int) $row['grace_days_snapshot'].'D')) > $now;
    }

    public function assertMayOperate(TenantId $tenantId): void
    {
        if (!$this->mayOperate($tenantId)) throw new \DomainException('L’abonnement Miksprit est en attente, suspendu ou expiré. Consultez la rubrique Abonnement pour reprendre l’accès opérationnel.');
    }
}
