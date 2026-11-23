<?php

declare(strict_types=1);

use App\Entity\Membership;
use App\Entity\MembershipStatus;
use App\Entity\TenantContext;
use App\Entity\TenantId;
use App\Entity\TenantRole;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$tenantA = new TenantId('tenant_members_a');
$tenantB = new TenantId('tenant_members_b');
$owner = TenantContext::fromMembership(Membership::owner('membership_owner_a', $tenantA, 'user_owner_a'), 'user_owner_a');
$otherOwner = TenantContext::fromMembership(Membership::owner('membership_owner_b', $tenantB, 'user_owner_b'), 'user_owner_b');
$operatorMembership = Membership::invite('membership_operator_a', $tenantA, 'user_operator_a', TenantRole::Operator);
$operatorMembership->accept('user_operator_a');
$operator = TenantContext::fromMembership($operatorMembership, 'user_operator_a');
$member = Membership::invite('membership_invited_a', $tenantA, 'user_invited_a', TenantRole::Operator);

$member->changeInvitationRole($owner, TenantRole::Analyst);
if ($member->status() !== MembershipStatus::Invited || $member->role() !== TenantRole::Analyst) {
    throw new RuntimeException('An owner could not change the role on a pending invitation.');
}
$member->accept('user_invited_a');
$member->changeRole($owner, TenantRole::Manager);
$member->suspend($owner);
$member->reactivate($owner);
$member->revoke($owner);
$member->reinvite($owner, TenantRole::Operator);
if ($member->status() !== MembershipStatus::Invited || $member->role() !== TenantRole::Operator || $member->revokedAt() !== null) {
    throw new RuntimeException('Revocation and re-invitation did not restore a clean pending membership.');
}

foreach ([
    fn () => $member->reinvite($otherOwner, TenantRole::Operator),
    fn () => $member->reinvite($operator, TenantRole::Operator),
] as $forbiddenAction) {
    try {
        $forbiddenAction();
        throw new RuntimeException('A non-owner or another tenant modified membership access.');
    } catch (DomainException $exception) {
        if ($exception->getMessage() === 'A non-owner or another tenant modified membership access.') throw $exception;
    }
}

echo "Tenant membership role, invitation, and authorization proof passed.\n";
