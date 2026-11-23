<?php

declare(strict_types=1);

namespace App\Service\Owner;

use App\Entity\Membership;
use App\Entity\TenantContext;
use App\Entity\TenantPermission;
use App\Entity\TenantRole;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Auth\AccountLinkIssuer;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final readonly class OwnerMembershipService
{
    public function __construct(
        private Connection $connection,
        private EntityManagerInterface $entities,
        private UserRepository $users,
        private UserPasswordHasherInterface $passwords,
        private AccountLinkIssuer $links,
    ) {}

    /** @return list<array{id:string,email:string,role:string,status:string,createdAt:string,acceptedAt:?string}> */
    public function list(TenantContext $tenant): array
    {
        $this->authorize($tenant);
        return self::membersView($this->connection->fetchAllAssociative(
            'SELECT m.id, u.email, m.role, m.status, m.created_at, m.accepted_at
             FROM tenant_memberships m INNER JOIN users u ON u.id = m.user_id
             WHERE m.tenant_id = ? ORDER BY CASE m.role WHEN \'owner\' THEN 0 ELSE 1 END, u.email',
            [$tenant->tenantId->value],
        ));
    }

    /** @return array{id:string,email:string,role:string,status:string} */
    public function invite(TenantContext $tenant, string $email, string $role): array
    {
        $this->authorize($tenant);
        $email = mb_strtolower(trim($email));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 190) throw new \InvalidArgumentException('Adresse e-mail invalide.');
        $memberRole = TenantRole::tryFrom($role);
        if ($memberRole === null || $memberRole === TenantRole::Owner) throw new \InvalidArgumentException('Choisissez un rôle de gestionnaire, opérateur ou analyste.');

        $user = $this->users->findByEmail($email);
        if ($user === null) {
            $user = User::invited(bin2hex(random_bytes(16)), $email, 'pending-password-setup');
            $temporaryPassword = bin2hex(random_bytes(32));
            $user->setInvitationPasswordHash($this->passwords->hashPassword($user, $temporaryPassword));
            $this->users->save($user);
        }
        if (!$user->isActive()) throw new \DomainException('Ce compte est désactivé par l’administration Miksprit.');

        $existingId = $this->connection->fetchOne(
            'SELECT id FROM tenant_memberships WHERE tenant_id = ? AND user_id = ?',
            [$tenant->tenantId->value, $user->id()],
        );
        if (is_string($existingId)) {
            $membership = $this->entities->find(Membership::class, $existingId);
            if (!$membership instanceof Membership) throw new \RuntimeException('Membership record could not be loaded.');
            if ($membership->status()->value === 'revoked') {
                $membership->reinvite($tenant, $memberRole);
                $this->entities->flush();
            } elseif ($membership->status()->value === 'invited') {
                $membership->changeInvitationRole($tenant, $memberRole);
                $this->entities->flush();
            } else {
                throw new \DomainException('Cet utilisateur est déjà membre de cet espace.');
            }
        } else {
            $membership = Membership::invite(bin2hex(random_bytes(16)), $tenant->tenantId, $user->id(), $memberRole);
            $this->entities->persist($membership);
            $this->entities->flush();
        }
        $this->links->sendInvitation($user);
        return ['id' => $membership->id, 'email' => $user->email(), 'role' => $membership->role()->value, 'status' => $membership->status()->value];
    }

    public function changeRole(TenantContext $tenant, string $membershipId, string $role): array
    {
        $this->authorize($tenant);
        $memberRole = TenantRole::tryFrom($role);
        if ($memberRole === null || $memberRole === TenantRole::Owner) throw new \InvalidArgumentException('Le rôle propriétaire ne peut pas être attribué depuis cette action.');
        $membership = $this->findMembership($tenant, $membershipId);
        $membership->changeRole($tenant, $memberRole);
        $this->entities->flush();
        return ['id' => $membership->id, 'role' => $membership->role()->value, 'status' => $membership->status()->value];
    }

    public function setStatus(TenantContext $tenant, string $membershipId, string $status): array
    {
        $this->authorize($tenant);
        $membership = $this->findMembership($tenant, $membershipId);
        match ($status) {
            'active' => $membership->reactivate($tenant),
            'suspended' => $membership->suspend($tenant),
            'revoked' => $membership->revoke($tenant),
            default => throw new \InvalidArgumentException('État de membre non pris en charge.'),
        };
        $this->entities->flush();
        return ['id' => $membership->id, 'role' => $membership->role()->value, 'status' => $membership->status()->value];
    }

    private function findMembership(TenantContext $tenant, string $membershipId): Membership
    {
        if (trim($membershipId) === '' || strlen($membershipId) > 160) throw new \DomainException('Membre introuvable dans cet espace.');
        $id = $this->connection->fetchOne('SELECT id FROM tenant_memberships WHERE id = ? AND tenant_id = ?', [$membershipId, $tenant->tenantId->value]);
        $membership = is_string($id) ? $this->entities->find(Membership::class, $id) : null;
        if (!$membership instanceof Membership) throw new \DomainException('Membre introuvable dans cet espace.');
        return $membership;
    }

    private function authorize(TenantContext $tenant): void
    {
        if (!$tenant->allows(TenantPermission::ManageMembers)) throw new \DomainException('Access denied for this tenant operation.');
    }

    /** @param list<array<string, mixed>> $rows @return list<array{id:string,email:string,role:string,status:string,createdAt:string,acceptedAt:?string}> */
    private static function membersView(array $rows): array
    {
        return array_map(static fn (array $row): array => [
            'id' => (string) $row['id'], 'email' => (string) $row['email'], 'role' => (string) $row['role'],
            'status' => (string) $row['status'], 'createdAt' => (string) $row['created_at'],
            'acceptedAt' => $row['accepted_at'] === null ? null : (string) $row['accepted_at'],
        ], $rows);
    }
}
