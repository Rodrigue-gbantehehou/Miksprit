<?php

declare(strict_types=1);

namespace App\Service\Owner;

use App\Entity\TenantContext;
use App\Entity\TenantPermission;
use App\Repository\OwnerReadRepository;

final readonly class OwnerReadService
{
    public function __construct(private OwnerReadRepository $repository)
    {
    }

    public function organizationsForActor(string $actorId): array
    {
        return $this->repository->organizationsForActor($actorId);
    }

    /** @return array{sites: int, routers: int, activeSessions: int, todaySalesXof: int} */
    public function dashboard(TenantContext $context): array
    {
        $this->assertPermission($context, TenantPermission::ReadReports);
        $this->assertPermission($context, TenantPermission::ReadSales);

        return $this->repository->dashboard($context);
    }

    public function sites(TenantContext $context): array
    {
        $this->assertPermission($context, TenantPermission::ManageSites);

        return $this->repository->sites($context);
    }

    public function plans(TenantContext $context): array
    {
        $this->assertPermission($context, TenantPermission::ManagePlans);

        return $this->repository->plans($context);
    }

    public function routers(TenantContext $context): array
    {
        $this->assertPermission($context, TenantPermission::ManageRouters);

        return $this->repository->routers($context);
    }

    public function transactions(TenantContext $context): array
    {
        $this->assertPermission($context, TenantPermission::ReadSales);

        return $this->repository->transactions($context);
    }

    private function assertPermission(TenantContext $context, TenantPermission $permission): void
    {
        if (!$context->allows($permission)) {
            throw new \DomainException('Access denied for this tenant operation.');
        }
    }
}
