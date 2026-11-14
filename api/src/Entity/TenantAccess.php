<?php

declare(strict_types=1);

namespace App\Entity;

final class TenantAccess
{
    /** Call before exposing or mutating any tenant-owned resource. */
    public static function assertOwns(TenantContext $context, TenantOwned $resource): void
    {
        if (!$context->tenantId->equals($resource->tenantId())) {
            // Deliberately generic: callers must not reveal whether another tenant owns the ID.
            throw new \DomainException('Resource not found.');
        }
    }
}
