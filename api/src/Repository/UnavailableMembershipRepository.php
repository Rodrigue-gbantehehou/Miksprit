<?php

declare(strict_types=1);

namespace App\Repository;

use App\Repository\MembershipRepository;
use App\Entity\Membership;
use App\Entity\TenantId;

final class UnavailableMembershipRepository implements MembershipRepository
{
    public function findForActorAndTenant(string $authenticatedActorId, TenantId $tenantId): ?Membership
    {
        throw new TenantMembershipPersistenceUnavailable();
    }

    public function findActiveForActor(string $authenticatedActorId): array
    {
        throw new TenantMembershipPersistenceUnavailable();
    }

    public function acceptInvitationsForActor(string $authenticatedActorId): void
    {
        throw new TenantMembershipPersistenceUnavailable();
    }
}
