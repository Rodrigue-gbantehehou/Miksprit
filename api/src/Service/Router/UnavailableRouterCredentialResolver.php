<?php

declare(strict_types=1);

namespace App\Service\Router;

use App\Service\Provider\Router\RouterOsCredentials;
final class UnavailableRouterCredentialResolver implements RouterCredentialResolver
{
    public function resolve(string $credentialReference): RouterOsCredentials
    {
        throw new HotspotProvisioningUnavailable();
    }
}
