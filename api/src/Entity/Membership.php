<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Membership is the authority for resolving a trusted actor's tenant scope. */
#[ORM\Entity]
#[ORM\Table(name: 'tenant_memberships')]
#[ORM\UniqueConstraint(name: 'uniq_tenant_member', columns: ['tenant_id', 'user_id'])]
final class Membership
{
    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 160)]
        public readonly string $id,
        #[ORM\Embedded(class: TenantId::class, columnPrefix: false)]
        public readonly TenantId $tenantId,
        #[ORM\Column(name: 'user_id', type: 'string', length: 160)]
        public readonly string $userId,
        #[ORM\Column(name: 'role', enumType: TenantRole::class, length: 16)]
        private TenantRole $membershipRole,
        #[ORM\Column(name: 'status', enumType: MembershipStatus::class, length: 16)]
        private MembershipStatus $membershipStatus,
        #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
        public readonly \DateTimeImmutable $createdAt,
        #[ORM\Column(name: 'accepted_at', type: 'datetime_immutable', nullable: true)]
        private ?\DateTimeImmutable $acceptedAt = null,
        #[ORM\Column(name: 'revoked_at', type: 'datetime_immutable', nullable: true)]
        private ?\DateTimeImmutable $revokedAt = null,
    ) {
        foreach ([$id, $userId] as $value) {
            if (trim($value) === '' || strlen($value) > 160) {
                throw new \InvalidArgumentException('Membership identifiers must be present and bounded.');
            }
        }
    }

    public static function invite(
        string $id,
        TenantId $tenantId,
        string $userId,
        TenantRole $role,
        ?\DateTimeImmutable $createdAt = null,
    ): self {
        return new self($id, $tenantId, $userId, $role, MembershipStatus::Invited, $createdAt ?? new \DateTimeImmutable());
    }

    public static function owner(
        string $id,
        TenantId $tenantId,
        string $userId,
        ?\DateTimeImmutable $createdAt = null,
    ): self {
        $now = $createdAt ?? new \DateTimeImmutable();

        return new self($id, $tenantId, $userId, TenantRole::Owner, MembershipStatus::Active, $now, $now);
    }

    public function status(): MembershipStatus
    {
        return $this->membershipStatus;
    }

    public function role(): TenantRole
    {
        return $this->membershipRole;
    }

    public function acceptedAt(): ?\DateTimeImmutable
    {
        return $this->acceptedAt;
    }

    public function revokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function isActiveFor(string $authenticatedActorId): bool
    {
        return $this->membershipStatus === MembershipStatus::Active
            && hash_equals($this->userId, $authenticatedActorId);
    }

    public function accept(string $authenticatedActorId, ?\DateTimeImmutable $acceptedAt = null): void
    {
        if ($this->membershipStatus !== MembershipStatus::Invited
            || !hash_equals($this->userId, $authenticatedActorId)) {
            throw new \DomainException('Invitation cannot be accepted by this actor.');
        }

        $this->membershipStatus = MembershipStatus::Active;
        $this->acceptedAt = $acceptedAt ?? new \DateTimeImmutable();
    }

    public function suspend(TenantContext $actor): void
    {
        $this->assertMembershipManager($actor);
        if ($this->membershipStatus !== MembershipStatus::Active || $this->membershipRole === TenantRole::Owner) {
            throw new \DomainException('Only an active non-owner membership can be suspended.');
        }
        $this->membershipStatus = MembershipStatus::Suspended;
    }

    public function reactivate(TenantContext $actor): void
    {
        $this->assertMembershipManager($actor);
        if ($this->membershipStatus !== MembershipStatus::Suspended) {
            throw new \DomainException('Only a suspended membership can be reactivated.');
        }
        $this->membershipStatus = MembershipStatus::Active;
    }

    public function revoke(TenantContext $actor, ?\DateTimeImmutable $revokedAt = null): void
    {
        $this->assertMembershipManager($actor);
        if ($this->membershipRole === TenantRole::Owner) {
            throw new \DomainException('Owner membership requires the ownership transfer workflow.');
        }
        if ($this->membershipStatus === MembershipStatus::Revoked) {
            return;
        }
        $this->membershipStatus = MembershipStatus::Revoked;
        $this->revokedAt = $revokedAt ?? new \DateTimeImmutable();
    }

    public function changeRole(TenantContext $actor, TenantRole $role): void
    {
        $this->assertMembershipManager($actor);
        if ($this->membershipStatus !== MembershipStatus::Active) {
            throw new \DomainException('Only an active membership can change role.');
        }
        if ($this->membershipRole === TenantRole::Owner || $role === TenantRole::Owner) {
            throw new \DomainException('Owner role changes require the ownership transfer workflow.');
        }

        $this->membershipRole = $role;
    }

    private function assertMembershipManager(TenantContext $actor): void
    {
        if (!$actor->tenantId->equals($this->tenantId) || !$actor->allows(TenantPermission::ManageMembers)) {
            throw new \DomainException('Actor is not allowed to manage this membership.');
        }
    }
}
