<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TenantContext;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(OwnerReadRepository::class)]
final readonly class DoctrineOwnerReadRepository implements OwnerReadRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function organizationsForActor(string $actorId): array
    {
        if (trim($actorId) === '' || strlen($actorId) > 160) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT o.tenant_id, o.name, m.role
             FROM tenant_memberships m
             INNER JOIN organizations o ON o.tenant_id = m.tenant_id
             WHERE m.user_id = :actor AND m.status = :membershipStatus AND o.organization_status = :organizationStatus
             ORDER BY o.name ASC, o.tenant_id ASC',
            ['actor' => $actorId, 'membershipStatus' => 'active', 'organizationStatus' => 'active'],
        );

        return array_map(static fn (array $row): array => [
            'id' => (string) $row['tenant_id'],
            'name' => (string) $row['name'],
            'role' => (string) $row['role'],
        ], $rows);
    }

    public function sites(TenantContext $context): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, site_name, status FROM sites WHERE tenant_id = :tenant ORDER BY site_name ASC, id ASC',
            ['tenant' => $context->tenantId->value],
        );

        return array_map(static fn (array $row): array => [
            'id' => (string) $row['id'],
            'name' => (string) $row['site_name'],
            'status' => (string) $row['status'],
        ], $rows);
    }

    public function plans(TenantContext $context): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, site_id, name, price_minor_units, session_seconds, data_limit_bytes, device_limit, publication_state
             FROM access_plans WHERE tenant_id = :tenant ORDER BY name ASC, id ASC',
            ['tenant' => $context->tenantId->value],
        );

        return array_map(static fn (array $row): array => [
            'id' => (string) $row['id'],
            'siteId' => (string) $row['site_id'],
            'name' => (string) $row['name'],
            'priceXof' => (int) $row['price_minor_units'],
            'sessionSeconds' => (int) $row['session_seconds'],
            'dataLimitBytes' => $row['data_limit_bytes'] === null ? null : (int) $row['data_limit_bytes'],
            'deviceLimit' => (int) $row['device_limit'],
            'durationLabel' => self::durationLabel((int) $row['session_seconds']),
            'status' => (string) $row['publication_state'],
        ], $rows);
    }

    public function routers(TenantContext $context): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT r.id, r.router_name, r.provider_code, r.status, r.router_os_version, s.site_name
             FROM routers r
             INNER JOIN sites s ON s.id = r.site_id AND s.tenant_id = r.tenant_id
             WHERE r.tenant_id = :tenant
             ORDER BY r.router_name ASC, r.id ASC',
            ['tenant' => $context->tenantId->value],
        );

        return array_map(static function (array $row): array {
            $result = [
                'id' => (string) $row['id'],
                'name' => (string) $row['router_name'],
                'siteName' => (string) $row['site_name'],
                'providerCode' => (string) $row['provider_code'],
                'status' => (string) $row['status'],
            ];
            if ($row['router_os_version'] !== null) {
                $result['version'] = (string) $row['router_os_version'];
            }

            return $result;
        }, $rows);
    }

    public function transactions(TenantContext $context): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT p.id, o.public_reference, p.expected_minor_units, p.state, o.created_at
             FROM payment_attempts p
             INNER JOIN wifi_orders o ON o.tenant_id = p.tenant_id AND o.public_reference = p.order_reference
             WHERE p.tenant_id = :tenant
             ORDER BY o.created_at DESC, p.id DESC
             LIMIT 200',
            ['tenant' => $context->tenantId->value],
        );

        return array_map(static fn (array $row): array => [
            'id' => (string) $row['id'],
            'reference' => (string) $row['public_reference'],
            'amountXof' => (int) $row['expected_minor_units'],
            'status' => (string) $row['state'],
            'createdAt' => (new \DateTimeImmutable((string) $row['created_at']))->format(DATE_ATOM),
        ], $rows);
    }

    public function dashboard(TenantContext $context): array
    {
        $tenant = $context->tenantId->value;
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d H:i:s');
        $tomorrow = (new \DateTimeImmutable('tomorrow'))->format('Y-m-d H:i:s');

        return [
            'sites' => (int) $this->connection->fetchOne("SELECT COUNT(*) FROM sites WHERE tenant_id = :tenant AND status = 'active'", ['tenant' => $tenant]),
            'routers' => (int) $this->connection->fetchOne('SELECT COUNT(*) FROM routers WHERE tenant_id = :tenant', ['tenant' => $tenant]),
            'activeSessions' => (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM hotspot_sessions WHERE tenant_id = :tenant AND state = :state',
                ['tenant' => $tenant, 'state' => 'active'],
            ),
            'todaySalesXof' => (int) $this->connection->fetchOne(
                'SELECT COALESCE(SUM(amount_minor), 0)
                 FROM ledger_entries
                 WHERE tenant_id = :tenant AND occurred_at >= :today AND occurred_at < :tomorrow AND entry_type = :entryType',
                ['tenant' => $tenant, 'today' => $today, 'tomorrow' => $tomorrow, 'entryType' => 'payment_collected'],
            ),
        ];
    }

    private static function durationLabel(int $seconds): string
    {
        if ($seconds < 3600) {
            return max(1, (int) ceil($seconds / 60)).' min';
        }

        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        return $minutes === 0 ? $hours.' h' : $hours.' h '.$minutes.' min';
    }
}
