<?php

declare(strict_types=1);

namespace App\Service\Owner;

use App\Entity\Membership;
use App\Entity\TenantContext;
use App\Entity\TenantId;
use App\Repository\MembershipRepository;
use App\Service\Tenant\ResolveTenantContext;

final readonly class ResolveOwnerContext
{
    public function __construct(
        private MembershipRepository $memberships,
        private ResolveTenantContext $resolveTenantContext,
    ) {
    }

    public function execute(string $actorId, ?string $requestedTenantId): TenantContext
    {
        if (trim($actorId) === '' || strlen($actorId) > 160) {
            throw new \DomainException('Owner access denied.');
        }

        if ($requestedTenantId !== null && trim($requestedTenantId) !== '') {
            try {
                return $this->resolveTenantContext->execute($actorId, new TenantId(trim($requestedTenantId)));
            } catch (\InvalidArgumentException|\DomainException $exception) {
                throw new \DomainException('Owner tenant context not found.', previous: $exception);
            }
        }

        $memberships = $this->memberships->findActiveForActor($actorId);
        if (count($memberships) !== 1 || !$memberships[0] instanceof Membership) {
            if (count($memberships) > 1) {
                throw new OwnerTenantSelectionRequired('Select an organization using the X-Tenant-ID header.');
            }
            throw new \DomainException('Owner tenant context not found.');
        }

        return TenantContext::fromMembership($memberships[0], $actorId);
    }
}
