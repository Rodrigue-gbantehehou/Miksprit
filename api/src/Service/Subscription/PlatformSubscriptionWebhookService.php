<?php

declare(strict_types=1);

namespace App\Service\Subscription;

use App\Repository\PaymentIdempotencyStore;
use App\Service\Payment\PaymentProviderRegistry;

final readonly class PlatformSubscriptionWebhookService
{
    public function __construct(
        private PaymentProviderRegistry $providers,
        private PaymentIdempotencyStore $idempotency,
        private PlatformSubscriptionService $subscriptions,
    ) {}

    public function process(string $provider, string $body, ?string $signature): void
    {
        if (strlen($body) > 65_536) throw new \UnexpectedValueException('Webhook payload exceeds the maximum size.');
        $event = $this->providers->parsePlatformWebhook($provider, $body, $signature);
        $key = hash('sha256', 'platform-subscription:'.$provider.':'.$event->providerReference.':'.$event->eventId);
        if (!$this->idempotency->claim($key)) return;
        try {
            if (str_starts_with($event->eventType, 'transaction.') && $this->subscriptions->isPaymentReference($provider, $event->providerReference)) {
                $verified = $this->providers->verifyLegacy($provider, $event->providerReference);
                if ($verified->provider !== $provider || $verified->providerReference !== $event->providerReference) {
                    throw new \DomainException('Verified subscription PSP transaction does not match the webhook reference.');
                }
                $this->subscriptions->applyVerifiedPayment($verified);
            }
            $this->idempotency->complete($key);
        } catch (\Throwable $exception) {
            try { $this->idempotency->release($key); } catch (\Throwable) {}
            throw $exception;
        }
    }

    public function signatureHeader(string $provider): string
    {
        return $this->providers->webhookSignatureHeader($provider);
    }
}
