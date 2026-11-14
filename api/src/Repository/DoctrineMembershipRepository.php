<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Membership;
use App\Entity\MembershipStatus;
use App\Entity\Organization;
use App\Entity\OrganizationStatus;
use App\Entity\TenantId;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/** Doctrine adapter that scopes by authenticated user, requested tenant, active membership and active organization. */
#[AsAlias(MembershipRepository::class)]
final readonly class DoctrineMembershipRepository implements MembershipRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function findForActorAndTenant(string $authenticatedActorId, TenantId $tenantId): ?Membership
    {
        if (trim($authenticatedActorId) === '' || strlen($authenticatedActorId) > 160) {
            return null;
        }

        $activeOrganization = $this->entityManager->createQueryBuilder()
            ->select('organization.id')
            ->from(Organization::class, 'organization')
            ->where('organization.id = :tenantId')
            ->andWhere('organization.organizationStatus = :organizationStatus')
            ->setParameter('tenantId', $tenantId->value)
            ->setParameter('organizationStatus', OrganizationStatus::Active)
            ->getQuery()
            ->getOneOrNullResult();

        if ($activeOrganization === null) {
            return null;
        }

        return $this->entityManager->createQueryBuilder()
            ->select('membership')
            ->from(Membership::class, 'membership')
            ->where('membership.tenantId.value = :tenantId')
            ->andWhere('membership.userId = :actorId')
            ->andWhere('membership.membershipStatus = :membershipStatus')
            ->setParameter('tenantId', $tenantId->value)
            ->setParameter('actorId', $authenticatedActorId)
            ->setParameter('membershipStatus', MembershipStatus::Active)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findActiveForActor(string $authenticatedActorId): array
    {
        if (trim($authenticatedActorId) === '' || strlen($authenticatedActorId) > 160) {
            return [];
        }

        return $this->entityManager->createQueryBuilder()
            ->select('membership')
            ->from(Membership::class, 'membership')
            ->innerJoin(Organization::class, 'organization', 'WITH', 'organization.id = membership.tenantId.value')
            ->where('membership.userId = :actorId')
            ->andWhere('membership.membershipStatus = :membershipStatus')
            ->andWhere('organization.organizationStatus = :organizationStatus')
            ->setParameter('actorId', $authenticatedActorId)
            ->setParameter('membershipStatus', MembershipStatus::Active)
            ->setParameter('organizationStatus', OrganizationStatus::Active)
            ->getQuery()->getResult();
    }

    public function acceptInvitationsForActor(string $authenticatedActorId): void
    {
        if (trim($authenticatedActorId) === '' || strlen($authenticatedActorId) > 160) {
            return;
        }
        $memberships = $this->entityManager->createQueryBuilder()
            ->select('membership')
            ->from(Membership::class, 'membership')
            ->where('membership.userId = :actorId')
            ->andWhere('membership.membershipStatus = :status')
            ->setParameter('actorId', $authenticatedActorId)
            ->setParameter('status', MembershipStatus::Invited)
            ->getQuery()->getResult();
        foreach ($memberships as $membership) {
            if ($membership instanceof Membership) {
                $membership->accept($authenticatedActorId);
            }
        }
        $this->entityManager->flush();
    }
}
