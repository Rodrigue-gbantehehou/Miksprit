<?php

declare(strict_types=1);

namespace App\Service\Router;

use App\Entity\TenantContext;
use App\Entity\TenantPermission;

final readonly class ListRouterSessions
{
    public function __construct(private VoucherProvisioningRepository $repository, private RouterManagementFactory $provisioners)
    {
    }

    /** @return list<array{externalId: string, username: string, address: ?string, macAddress: ?string, uptime: ?string, bytesIn: ?string, bytesOut: ?string}> */
    public function execute(TenantContext $context, string $routerId): array
    {
        if (!$context->allows(TenantPermission::ManageSessions)) {
            throw new \DomainException('Session access is not permitted.');
        }
        $router = $this->repository->findRouterForTenant($context, $routerId);
        if ($router === null) {
            throw new \DomainException('Router not found.');
        }

        return $this->provisioners->forRouterManagement($router)->activeSessions();
    }
}
