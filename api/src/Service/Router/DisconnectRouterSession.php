<?php

declare(strict_types=1);

namespace App\Service\Router;

use App\Entity\TenantContext;
use App\Entity\TenantPermission;

final readonly class DisconnectRouterSession
{
    public function __construct(private VoucherProvisioningRepository $repository, private RouterManagementFactory $provisioners)
    {
    }

    public function execute(TenantContext $context, string $routerId, string $externalSessionId): bool
    {
        if (!$context->allows(TenantPermission::ManageSessions)) {
            throw new \DomainException('Session management is not permitted.');
        }
        $router = $this->repository->findRouterForTenant($context, $routerId);
        if ($router === null) {
            throw new \DomainException('Router not found.');
        }

        return $this->provisioners->forRouterManagement($router)->disconnectSession($externalSessionId);
    }
}
