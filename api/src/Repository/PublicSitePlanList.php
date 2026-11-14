<?php

declare(strict_types=1);

namespace App\Repository;

final readonly class PublicSitePlanList
{
    /** @param list<array{id: string, name: string, priceXof: int, sessionSeconds: int, dataLimitBytes: ?int, deviceLimit: int}> $plans */
    public function __construct(
        public string $siteId,
        public string $siteName,
        public array $plans,
    ) {
    }
}
