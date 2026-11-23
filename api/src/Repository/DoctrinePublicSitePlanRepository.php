<?php

declare(strict_types=1);

namespace App\Repository;

use Doctrine\DBAL\Connection;
use App\Entity\TenantId;
use App\Service\Subscription\TenantSubscriptionAccess;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(PublicSitePlanRepository::class)]
final readonly class DoctrinePublicSitePlanRepository implements PublicSitePlanRepository
{
    public function __construct(private Connection $connection, private TenantSubscriptionAccess $subscriptionAccess)
    {
    }

    public function findPublishedPlansForActiveSite(string $siteId): ?PublicSitePlanList
    {
        $site = $this->connection->fetchAssociative(
            'SELECT s.id, s.site_name, s.tenant_id
             FROM sites s
             INNER JOIN organizations o ON o.tenant_id = s.tenant_id
             WHERE s.id = :siteId AND s.status = :siteStatus AND o.organization_status = :organizationStatus
               AND (SELECT COUNT(*) FROM routers r WHERE r.tenant_id = s.tenant_id AND r.site_id = s.id AND r.status = :routerStatus) = 1',
            ['siteId' => $siteId, 'siteStatus' => 'active', 'organizationStatus' => 'active', 'routerStatus' => 'active'],
        );

        if ($site === false) {
            return null;
        }
        if (!$this->subscriptionAccess->mayOperate(new TenantId((string) $site['tenant_id']))) return null;

        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, name, price_minor_units, session_seconds, data_limit_bytes, device_limit
             FROM access_plans
             WHERE site_id = :siteId AND tenant_id = :tenantId AND publication_state = :publicationState
             ORDER BY price_minor_units ASC, id ASC',
            ['siteId' => $site['id'], 'tenantId' => $site['tenant_id'], 'publicationState' => 'published'],
        );

        $plans = array_map(static fn (array $row): array => [
            'id' => (string) $row['id'],
            'name' => (string) $row['name'],
            'priceXof' => (int) $row['price_minor_units'],
            'sessionSeconds' => (int) $row['session_seconds'],
            'dataLimitBytes' => $row['data_limit_bytes'] === null ? null : (int) $row['data_limit_bytes'],
            'deviceLimit' => (int) $row['device_limit'],
        ], $rows);

        return new PublicSitePlanList((string) $site['id'], (string) $site['site_name'], $plans);
    }
}
