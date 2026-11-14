<?php

declare(strict_types=1);

namespace App\Service\Router;

use App\Entity\Router;

/** Safe default until a certificate-verified RouterOS client and secret resolver are configured. */
final class UnavailableHotspotProvisionerFactory implements HotspotProvisionerFactory
{
    public function forRouter(Router $router): HotspotProvisioningPort
    {
        return new class implements HotspotProvisioningPort {
            public function createUser(string $username, string $password, string $profile, ?string $limitUptime, ?int $limitBytes = null, int $sharedUsers = 1): void
            {
                throw new HotspotProvisioningUnavailable();
            }

        };
    }
}
