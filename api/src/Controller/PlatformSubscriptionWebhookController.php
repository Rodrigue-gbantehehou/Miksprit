<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Subscription\PlatformSubscriptionWebhookService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class PlatformSubscriptionWebhookController
{
    public function __construct(private PlatformSubscriptionWebhookService $webhooks) {}

    #[Route('/api/v1/webhooks/platform-subscriptions/{provider}', name: 'api_platform_subscription_webhook', methods: ['POST'], requirements: ['provider' => '[a-z][a-z0-9_-]{1,39}'])]
    public function __invoke(Request $request, string $provider): JsonResponse
    {
        try {
            $this->webhooks->process($provider, $request->getContent(), $request->headers->get($this->webhooks->signatureHeader($provider)));
        } catch (\UnexpectedValueException|\InvalidArgumentException) {
            return new JsonResponse(['error' => 'invalid_webhook'], 400, ['Cache-Control' => 'no-store']);
        } catch (\Throwable) {
            return new JsonResponse(['error' => 'temporarily_unavailable'], 503, ['Cache-Control' => 'no-store']);
        }
        return new JsonResponse(['received' => true], 200, ['Cache-Control' => 'no-store']);
    }
}
