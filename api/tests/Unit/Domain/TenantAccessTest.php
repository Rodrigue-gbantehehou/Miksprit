<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain;

use App\Entity\TenantAccess;
use App\Entity\TenantContext;
use App\Entity\TenantId;
use App\Entity\Membership;
use App\Entity\TenantOwned;
use PHPUnit\Framework\TestCase;

final class TenantAccessTest extends TestCase
{
    public function testCrossTenantResourceIsHiddenAsNotFound(): void
    {
        $context = TenantContext::fromMembership(Membership::owner('membership_1', new TenantId('tenant_1'), 'user_1'), 'user_1');
        $resource = new class implements TenantOwned {
            public function tenantId(): TenantId
            {
                return new TenantId('tenant_2');
            }
        };

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Resource not found.');
        TenantAccess::assertOwns($context, $resource);
    }
}
