<?php

declare(strict_types=1);

namespace App\Service\Router;

use App\Entity\TenantId;

interface RouterProvisioningTargetRepository
{
    public function findTargetForTenant(TenantId $tenantId, string $routerId): ?RouterProvisioningTarget;
}
