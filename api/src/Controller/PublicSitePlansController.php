<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Catalogue\PublicSitePlans;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final readonly class PublicSitePlansController
{
    public function __construct(private PublicSitePlans $publicSitePlans)
    {
    }

    #[Route('/api/v1/public/sites/{siteId}/plans', name: 'api_public_site_plans', methods: ['GET'])]
    public function __invoke(string $siteId): JsonResponse
    {
        $result = $this->publicSitePlans->forSite($siteId);
        if ($result === null) {
            return new JsonResponse(['error' => 'site_not_found'], JsonResponse::HTTP_NOT_FOUND);
        }

        return new JsonResponse([
            'site' => [
                'id' => $result->siteId,
                'name' => $result->siteName,
            ],
            'plans' => $result->plans,
        ]);
    }
}
