<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Payment\GetPublicWifiOrderStatus;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final readonly class PublicPaymentStatusController
{
    public function __construct(private GetPublicWifiOrderStatus $checkout)
    {
    }

    #[Route('/api/v1/public/orders/{reference}/status', name: 'api_public_order_status', methods: ['GET'], requirements: ['reference' => '[a-f0-9]{64}'])]
    public function __invoke(string $reference): JsonResponse
    {
        $status = $this->checkout->execute($reference);
        if ($status === null) return new JsonResponse(['error' => 'order_not_found'], JsonResponse::HTTP_NOT_FOUND, ['Cache-Control' => 'no-store']);
        return new JsonResponse($status, JsonResponse::HTTP_OK, ['Cache-Control' => 'no-store']);
    }
}
