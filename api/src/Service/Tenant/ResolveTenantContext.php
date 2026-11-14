<?php

declare(strict_types=1);

namespace App\Service\Tenant;

use App\Entity\TenantContext;
use App\Entity\TenantId;
use App\Repository\MembershipRepository;

/** Validates a client-selected tenant against the authenticated actor's persisted active membership. */
final readonly class ResolveTenantContext
{
    public function __construct(private MembershipRepository $memberships)
    {
    }

    public function execute(string $authenticatedActorId, TenantId $requestedTenantId): TenantContext
    {
        if (trim($authenticatedActorId) === '') {
            throw new \DomainException('Tenant context not found.');
        }
        $membership = $this->memberships->findForActorAndTenant($authenticatedActorId, $requestedTenantId);
        if ($membership === null) {
            throw new \DomainException('Tenant context not found.');
        }

        try {
            return TenantContext::fromMembership($membership, $authenticatedActorId);
        } catch (\DomainException $exception) {
            throw new \DomainException('Tenant context not found.', previous: $exception);
        }
    }
}
