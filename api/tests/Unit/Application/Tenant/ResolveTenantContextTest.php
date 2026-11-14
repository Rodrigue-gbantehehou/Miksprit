<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Tenant;

use App\Repository\MembershipRepository;
use App\Service\Tenant\ResolveTenantContext;
use App\Entity\Membership;
use App\Entity\TenantId;
use PHPUnit\Framework\TestCase;

final class ResolveTenantContextTest extends TestCase
{
    public function testResolvesTenantFromAuthenticatedActorsMembership(): void
    {
        $tenantId = new TenantId('tenant_001');
        $membership = Membership::owner('membership_001', $tenantId, 'actor_001');
        $repository = new class($membership) implements MembershipRepository {
            public function __construct(private readonly Membership $membership) {}
            public function findForActorAndTenant(string $authenticatedActorId, TenantId $tenantId): ?Membership
            {
                return $authenticatedActorId === $this->membership->userId && $tenantId->equals($this->membership->tenantId)
                    ? $this->membership
                    : null;
            }
        };

        $context = (new ResolveTenantContext($repository))->execute('actor_001', $tenantId);

        self::assertSame('tenant_001', $context->tenantId->value);
        self::assertSame('actor_001', $context->actorId);
    }

    public function testUnknownOrForeignTenantDoesNotRevealMembership(): void
    {
        $repository = new class implements MembershipRepository {
            public function findForActorAndTenant(string $authenticatedActorId, TenantId $tenantId): ?Membership { return null; }
        };

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Tenant context not found.');
        (new ResolveTenantContext($repository))->execute('actor_001', new TenantId('tenant_foreign'));
    }
}
