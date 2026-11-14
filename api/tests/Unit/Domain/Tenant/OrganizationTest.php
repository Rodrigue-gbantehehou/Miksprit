<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Tenant;

use App\Entity\Organization;
use App\Entity\OrganizationStatus;
use App\Entity\Membership;
use App\Entity\TenantContext;
use App\Entity\TenantId;
use PHPUnit\Framework\TestCase;

final class OrganizationTest extends TestCase
{
    public function testOrganizationHasStableTenantIdAndCanBeSuspended(): void
    {
        $tenantId = new TenantId('tenant_001');
        $organization = Organization::create($tenantId, 'Wi-Fi Littoral', 'wifi-littoral');

        self::assertTrue($organization->tenantId()->equals($tenantId));
        self::assertSame('Wi-Fi Littoral', $organization->name());
        self::assertSame(OrganizationStatus::Active, $organization->status());

        $ownerMembership = Membership::owner('owner_001', $tenantId, 'owner_0001');
        $actor = TenantContext::fromMembership($ownerMembership, 'owner_0001');
        $organization->suspend($actor);
        self::assertSame(OrganizationStatus::Suspended, $organization->status());
    }

    public function testSlugMustBeUrlSafe(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Organization::create(new TenantId('tenant_001'), 'Company', '../other-tenant');
    }

    public function testNonOwnerCannotSuspendOrganization(): void
    {
        $tenantId = new TenantId('tenant_001');
        $organization = Organization::create($tenantId, 'Company', 'company');
        $operator = Membership::invite('member_001', $tenantId, 'user_0002', \App\Entity\TenantRole::Operator);
        $operator->accept('user_0002');
        $actor = TenantContext::fromMembership($operator, 'user_0002');

        $this->expectException(\DomainException::class);
        $organization->suspend($actor);
    }
}
