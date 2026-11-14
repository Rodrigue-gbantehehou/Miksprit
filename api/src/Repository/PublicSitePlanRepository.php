<?php

declare(strict_types=1);

namespace App\Repository;

interface PublicSitePlanRepository
{
    public function findPublishedPlansForActiveSite(string $siteId): ?PublicSitePlanList;
}
