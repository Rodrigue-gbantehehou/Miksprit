<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Membership;
use App\Entity\TenantId;

/** Resolves active membership only for the authenticated actor and an active organization. */
interface MembershipRepository
{
    public function findForActorAndTenant(string $authenticatedActorId, TenantId $tenantId): ?Membership;

    /** @return list<Membership> */
    public function findActiveForActor(string $authenticatedActorId): array;

    /** Activate all pending invitations addressed to the authenticated actor. */
    public function acceptInvitationsForActor(string $authenticatedActorId): void;
}
