<?php

declare(strict_types=1);

namespace App\Service\Owner;

use App\Entity\Membership;
use App\Entity\Organization;
use App\Entity\TenantId;
use App\Entity\User;
use App\Repository\MembershipRepository;
use App\Repository\UserRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

/** Creates the first owner workspace for an authenticated account without an invitation. */
final readonly class CreateOwnerOrganization
{
    public function __construct(
        private UserRepository $users,
        private MembershipRepository $memberships,
        private EntityManagerInterface $entities,
    ) {
    }

    /** @return array{id: string, name: string, slug: string, role: string} */
    public function execute(string $actorId, string $name, string $slug): array
    {
        $name = trim($name);
        $slug = mb_strtolower(trim($slug));
        if ($name === '' || mb_strlen($name) > 160
            || preg_match('/^[a-z0-9](?:[a-z0-9-]{0,78}[a-z0-9])?$/D', $slug) !== 1) {
            throw new \InvalidArgumentException('Le nom ou l’identifiant URL de l’espace est invalide.');
        }

        $owner = $this->users->findById($actorId);
        if (!$owner instanceof User || !$owner->isActive()) {
            throw new OwnerOrganizationConflict('Ce compte ne peut pas créer d’espace Miksprit.');
        }
        if ($this->memberships->findActiveForActor($actorId) !== []) {
            throw new OwnerOrganizationConflict('Ce compte possède déjà un espace actif.');
        }
        if ($this->entities->getRepository(Organization::class)->findOneBy(['slug' => $slug]) !== null) {
            throw new OwnerOrganizationConflict('Cet identifiant URL est déjà utilisé.');
        }

        $tenantId = new TenantId(bin2hex(random_bytes(16)));
        $organization = Organization::create($tenantId, $name, $slug);
        $membership = Membership::owner(bin2hex(random_bytes(16)), $tenantId, $owner->id());

        try {
            $this->entities->wrapInTransaction(function (EntityManagerInterface $entities) use ($organization, $membership): void {
                $entities->persist($organization);
                $entities->persist($membership);
            });
        } catch (UniqueConstraintViolationException $exception) {
            throw new OwnerOrganizationConflict('Cet identifiant URL est déjà utilisé.', previous: $exception);
        }

        return ['id' => $tenantId->value, 'name' => $name, 'slug' => $slug, 'role' => 'owner'];
    }
}
