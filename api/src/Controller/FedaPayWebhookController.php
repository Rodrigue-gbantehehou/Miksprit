<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Provider\Payment\IdempotentPaymentWebhookService;
use App\Service\Provider\Payment\InvalidPaymentWebhook;
use App\Service\Provider\Payment\PaymentProviderUnavailable;
use App\Service\Provider\Payment\PaymentPersistenceUnavailable;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use App\Service\Payment\PaymentProviderRegistry;
use App\Service\Payment\UnknownPaymentProvider;

final readonly class FedaPayWebhookController
{
    public function __construct(private IdempotentPaymentWebhookService $webhooks, private PaymentProviderRegistry $providers)
    {
    }

    #[Route('/api/v1/webhooks/{provider}', name: 'api_payment_webhook', methods: ['POST'], requirements: ['provider' => '[a-z][a-z0-9_-]{1,39}'])]
    public function __invoke(Request $request, string $provider): JsonResponse
    {
        if (strlen($request->getContent()) > 65_536) {
            return new JsonResponse(['error' => 'invalid_webhook'], JsonResponse::HTTP_BAD_REQUEST, ['Cache-Control' => 'no-store']);
        }

        try {
            $header = $this->providers->webhookSignatureHeader($provider);
            $this->webhooks->process($provider, $request->getContent(), $request->headers->get($header));
        } catch (InvalidPaymentWebhook|UnknownPaymentProvider) {
            return new JsonResponse(['error' => 'invalid_webhook'], JsonResponse::HTTP_BAD_REQUEST, ['Cache-Control' => 'no-store']);
        } catch (PaymentProviderUnavailable|PaymentPersistenceUnavailable) {
            return new JsonResponse(['error' => 'temporarily_unavailable'], JsonResponse::HTTP_SERVICE_UNAVAILABLE, ['Cache-Control' => 'no-store']);
        } catch (\Throwable) {
            return new JsonResponse(['error' => 'temporarily_unavailable'], JsonResponse::HTTP_SERVICE_UNAVAILABLE, ['Cache-Control' => 'no-store']);
        }

        return new JsonResponse(['received' => true], JsonResponse::HTTP_OK, ['Cache-Control' => 'no-store']);
    }
}
