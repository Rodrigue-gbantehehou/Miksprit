<?php

declare(strict_types=1);

namespace App\Service\Catalogue;

use App\Repository\PublicSitePlanList;
use App\Repository\PublicSitePlanRepository;

final readonly class PublicSitePlans
{
    public function __construct(private PublicSitePlanRepository $repository)
    {
    }

    public function forSite(string $siteId): ?PublicSitePlanList
    {
        if (preg_match('/^[A-Za-z0-9_-]{8,80}$/D', $siteId) !== 1) {
            return null;
        }

        return $this->repository->findPublishedPlansForActiveSite($siteId);
    }
}
