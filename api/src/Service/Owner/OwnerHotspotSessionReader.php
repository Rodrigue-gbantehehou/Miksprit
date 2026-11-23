<?php

declare(strict_types=1);

namespace App\Service\Owner;

use App\Entity\TenantContext;
use App\Entity\TenantPermission;
use Doctrine\DBAL\Connection;

final readonly class OwnerHotspotSessionReader
{
    public function __construct(private Connection $connection) {}

    /** @return list<array<string,mixed>> */
    public function list(TenantContext $tenant): array
    {
        if (!$tenant->allows(TenantPermission::ManageSessions)) throw new \DomainException('Access denied for this tenant operation.');
        return array_map(static fn (array $row): array => [
            'id' => (string) $row['id'], 'routerName' => (string) $row['router_name'], 'siteName' => (string) $row['site_name'],
            'voucherId' => (string) $row['voucher_id'], 'status' => (string) $row['state'],
            'startedAt' => $row['started_at'] === null ? null : (string) $row['started_at'],
            'endedAt' => $row['ended_at'] === null ? null : (string) $row['ended_at'],
            'bytesIn' => $row['bytes_in'] === null ? null : (string) $row['bytes_in'],
            'bytesOut' => $row['bytes_out'] === null ? null : (string) $row['bytes_out'],
        ], $this->connection->fetchAllAssociative(
            'SELECT hs.id, hs.voucher_id, hs.state, hs.started_at, hs.ended_at, hs.bytes_in, hs.bytes_out, r.router_name, s.site_name AS site_name
             FROM hotspot_sessions hs
             INNER JOIN routers r ON r.tenant_id = hs.tenant_id AND r.id = hs.router_id
             INNER JOIN sites s ON s.tenant_id = hs.tenant_id AND s.id = r.site_id
             WHERE hs.tenant_id = ? ORDER BY hs.created_at DESC LIMIT 200',
            [$tenant->tenantId->value],
        ));
    }
}
