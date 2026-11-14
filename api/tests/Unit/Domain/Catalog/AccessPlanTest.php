<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Catalog;

use App\Entity\AccessPlan;
use App\Entity\PlanPublicationState;
use App\Entity\XofAmount;
use App\Entity\TenantId;
use PHPUnit\Framework\TestCase;

final class AccessPlanTest extends TestCase
{
    public function testPurchasableSnapshotKeepsTermsWhenPlanChangesLater(): void
    {
        $plan = new AccessPlan('plan_1', new TenantId('tenant_1'), 'site_1', '1 heure', new XofAmount(500), 3600, null, 1);
        $plan->publish();
        $snapshot = $plan->snapshot();

        $plan->updateTerms('2 heures', new XofAmount(900), 7200, null, 2);

        self::assertSame(500, $snapshot->price->minorUnits);
        self::assertSame(3600, $snapshot->sessionSeconds);
        self::assertSame('1 heure', $snapshot->name);
        self::assertSame(2, $plan->snapshot()->version);
    }

    public function testDraftAndArchivedPlansCannotBePurchased(): void
    {
        $plan = new AccessPlan('plan_1', new TenantId('tenant_1'), 'site_1', '1 heure', new XofAmount(500), 3600, null);
        try {
            $plan->snapshot();
            self::fail('Draft plan must not be purchasable.');
        } catch (\DomainException) {
            $plan->publish();
            $plan->archive();
            self::assertSame(PlanPublicationState::Archived, $plan->publicationState());
            $this->expectException(\DomainException::class);
            $plan->snapshot();
        }
    }
}
