<?php

declare(strict_types=1);

namespace App\Service\Router;

use App\Entity\Router;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(HotspotProvisionerFactory::class)]
#[AsAlias(RouterManagementFactory::class)]
final readonly class RouterOsHotspotProvisionerFactory implements HotspotProvisionerFactory, RouterManagementFactory
{
    public function __construct(
        private RouterProvisioningTargetRepository $targets,
        private RouterCredentialResolver $credentials,
        private RouterDriverRegistry $drivers,
    ) {
    }

    public function forRouter(Router $router): HotspotProvisioningPort
    {
        return $this->forRouterManagement($router);
    }

    public function forRouterManagement(Router $router): RouterManagementPort
    {
        $target = $this->targets->findTargetForTenant($router->tenantId(), $router->id);
        if ($target === null) {
            throw new HotspotProvisioningUnavailable();
        }
        $driver = $this->drivers->get($router->providerCode());
        if ($target->providerCode() !== $router->providerCode()) {
            throw new \DomainException('Router provider configuration changed; reload and retry.');
        }
        $credentials = $this->credentials->resolve($router->tenantId(), $target->credentialReference());

        return $driver->connect($target, $credentials);
    }
}
