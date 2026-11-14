<?php

declare(strict_types=1);

namespace App\Entity;

/** Tenant scope resolved by trusted authentication/authorization code. */
final readonly class TenantContext
{
    private function __construct(
        public TenantId $tenantId,
        public string $actorId,
        public TenantRole $role,
    )
    {
        if ($actorId === '' || strlen($actorId) > 160) {
            throw new \InvalidArgumentException('A valid authenticated actor is required.');
        }
    }

    /** Build tenant scope only from a persisted membership belonging to the authenticated principal. */
    public static function fromMembership(Membership $membership, string $authenticatedActorId): self
    {
        if (!$membership->isActiveFor($authenticatedActorId)) {
            throw new \DomainException('Tenant membership is not active for this actor.');
        }

        return new self($membership->tenantId, $authenticatedActorId, $membership->role());
    }

    public function allows(TenantPermission $permission): bool
    {
        return $this->role->allows($permission);
    }
}
