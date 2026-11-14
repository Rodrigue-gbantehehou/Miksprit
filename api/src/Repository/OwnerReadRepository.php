<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TenantContext;

interface OwnerReadRepository
{
    /** @return list<array{id: string, name: string, role: string}> */
    public function organizationsForActor(string $actorId): array;

    /** @return list<array{id: string, name: string, status: string}> */
    public function sites(TenantContext $context): array;

    /** @return list<array{id: string, siteId: string, name: string, priceXof: int, sessionSeconds: int, dataLimitBytes: ?int, deviceLimit: int, durationLabel: string, status: string}> */
    public function plans(TenantContext $context): array;

    /** @return list<array{id: string, name: string, siteName: string, providerCode: string, status: string, version?: string}> */
    public function routers(TenantContext $context): array;

    /** @return list<array{id: string, reference: string, amountXof: int, status: string, createdAt: string}> */
    public function transactions(TenantContext $context): array;

    /** @return array{sites: int, routers: int, activeSessions: int, todaySalesXof: int} */
    public function dashboard(TenantContext $context): array;
}
