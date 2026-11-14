<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Tenant;

use App\Entity\Membership;
use App\Entity\MembershipStatus;
use App\Entity\TenantContext;
use App\Entity\TenantId;
use App\Entity\TenantPermission;
use App\Entity\TenantRole;
use PHPUnit\Framework\TestCase;

final class MembershipTest extends TestCase
{
    public function testOnlyActiveMembershipForAuthenticatedUserCanCreateTenantContext(): void
    {
        $membership = Membership::owner('member_001', new TenantId('tenant_001'), 'user_0001');

        $context = TenantContext::fromMembership($membership, 'user_0001');

        self::assertSame('tenant_001', $context->tenantId->value);
        self::assertSame('user_0001', $context->actorId);
        self::assertSame(TenantRole::Owner, $context->role);
        self::assertTrue($context->allows(TenantPermission::ManageMembers));
    }

    public function testMembershipForAnotherUserCannotMintContext(): void
    {
        $membership = Membership::owner('member_001', new TenantId('tenant_001'), 'user_0001');

        $this->expectException(\DomainException::class);
        TenantContext::fromMembership($membership, 'user_other');
    }

    public function testInvitationIsInactiveUntilMatchingUserAcceptsIt(): void
    {
        $membership = Membership::invite('member_001', new TenantId('tenant_001'), 'user_0001', TenantRole::Operator);

        try {
            TenantContext::fromMembership($membership, 'user_0001');
            self::fail('An invitation must not authorize tenant access before acceptance.');
        } catch (\DomainException) {
            self::assertSame(MembershipStatus::Invited, $membership->status());
        }

        $membership->accept('user_0001');

        self::assertSame(MembershipStatus::Active, $membership->status());
        self::assertSame(TenantRole::Operator, TenantContext::fromMembership($membership, 'user_0001')->role);
    }

    public function testSuspendedAndRevokedMembershipCannotCreateContext(): void
    {
        $tenantId = new TenantId('tenant_001');
        $owner = Membership::owner('owner_001', $tenantId, 'owner_0001');
        $actor = TenantContext::fromMembership($owner, 'owner_0001');
        $membership = Membership::invite('member_001', $tenantId, 'user_0001', TenantRole::Operator);
        $membership->accept('user_0001');
        $membership->suspend($actor);

        try {
            TenantContext::fromMembership($membership, 'user_0001');
            self::fail('Suspended memberships must not authorize tenant access.');
        } catch (\DomainException) {
            self::assertSame(MembershipStatus::Suspended, $membership->status());
        }

        $membership->reactivate($actor);
        $membership->revoke($actor);
        $this->expectException(\DomainException::class);
        TenantContext::fromMembership($membership, 'user_0001');
    }

    public function testOperatorCannotManageOrganizationOrMembers(): void
    {
        $membership = Membership::invite('member_001', new TenantId('tenant_001'), 'user_0002', TenantRole::Operator);
        $membership->accept('user_0002');
        $context = TenantContext::fromMembership($membership, 'user_0002');

        self::assertTrue($context->allows(TenantPermission::ManageVouchers));
        self::assertFalse($context->allows(TenantPermission::ManageMembers));
        self::assertFalse($context->allows(TenantPermission::ManageOrganization));

        $target = Membership::invite('member_002', new TenantId('tenant_001'), 'user_0003', TenantRole::Operator);
        $target->accept('user_0003');
        $this->expectException(\DomainException::class);
        $target->suspend($context);
    }

    public function testRoleChangeRequiresActiveTargetAndOwnerTransferWorkflow(): void
    {
        $tenantId = new TenantId('tenant_001');
        $actor = TenantContext::fromMembership(Membership::owner('owner_001', $tenantId, 'owner_0001'), 'owner_0001');
        $invited = Membership::invite('member_002', $tenantId, 'user_0003', TenantRole::Operator);

        $this->expectException(\DomainException::class);
        $invited->changeRole($actor, TenantRole::Analyst);
    }
}
