<?php

declare(strict_types=1);

namespace App\Entity;

interface TenantOwned
{
    public function tenantId(): TenantId;
}
