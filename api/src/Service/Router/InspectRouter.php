<?php

declare(strict_types=1);

namespace App\Service\Router;

use App\Entity\TenantContext;
use App\Entity\TenantPermission;

final readonly class InspectRouter
{
    public function __construct(private VoucherProvisioningRepository $repository, private RouterManagementFactory $provisioners)
    {
    }

    /** @return array{version: string, uptime: string, identity: string} */
    public function execute(TenantContext $context, string $routerId): array
    {
        if (!$context->allows(TenantPermission::ManageRouters)) {
            throw new \DomainException('Router access is not permitted.');
        }
        $router = $this->repository->findRouterForTenant($context, $routerId);
        if ($router === null) {
            throw new \DomainException('Router not found.');
        }

        return $this->provisioners->forRouterManagement($router)->health();
    }
}
