<?php

declare(strict_types=1);

namespace App\Service\Router;

use App\Entity\Router;

/** Resolves the router's secret reference into a short-lived, fail-closed provisioning port. */
interface HotspotProvisionerFactory
{
    public function forRouter(Router $router): HotspotProvisioningPort;
}
