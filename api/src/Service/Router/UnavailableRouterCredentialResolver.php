<?php

declare(strict_types=1);

namespace App\Service\Router;

use App\Entity\TenantId;
use App\Service\Provider\Router\RouterOsCredentials;
final class UnavailableRouterCredentialResolver implements RouterCredentialResolver
{
    public function resolve(TenantId $tenantId, string $credentialReference): RouterOsCredentials
    {
        throw new HotspotProvisioningUnavailable();
    }
}
