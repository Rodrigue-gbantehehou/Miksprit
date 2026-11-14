<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\PublicSitePlansController;
use App\Repository\PublicSitePlanList;
use App\Repository\PublicSitePlanRepository;
use App\Service\Catalogue\PublicSitePlans;
use PHPUnit\Framework\TestCase;

final class PublicSitePlansControllerTest extends TestCase
{
    public function testReturnsOnlyPublicPlanAllowlist(): void
    {
        $repository = new FixedPublicSitePlanRepository(new PublicSitePlanList('site_0001', 'Centre', [[
            'id' => 'plan_0001',
            'name' => '1 heure',
            'priceXof' => 500,
            'sessionSeconds' => 3600,
            'dataLimitBytes' => null,
            'deviceLimit' => 1,
        ]]));
        $response = (new PublicSitePlansController(new PublicSitePlans($repository)))('site_0001');
        $decoded = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['site' => ['id' => 'site_0001', 'name' => 'Centre'], 'plans' => [[
            'id' => 'plan_0001',
            'name' => '1 heure',
            'priceXof' => 500,
            'sessionSeconds' => 3600,
            'dataLimitBytes' => null,
            'deviceLimit' => 1,
        ]]], $decoded);
    }

    public function testInvalidOrInactiveSiteReturnsGenericNotFound(): void
    {
        $response = (new PublicSitePlansController(new PublicSitePlans(new FixedPublicSitePlanRepository(null))))('site_0001');

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('{"error":"site_not_found"}', $response->getContent());
    }
}

final readonly class FixedPublicSitePlanRepository implements PublicSitePlanRepository
{
    public function __construct(private ?PublicSitePlanList $result)
    {
    }

    public function findPublishedPlansForActiveSite(string $siteId): ?PublicSitePlanList
    {
        return $this->result;
    }
}
