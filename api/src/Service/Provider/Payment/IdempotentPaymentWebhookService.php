<?php

declare(strict_types=1);

namespace App\Service\Provider\Payment;

use App\Repository\PaymentIdempotencyStore;
use App\Service\Payment\PaymentProviderRegistry;
use App\Service\Payment\PaymentWebhookConsumer;

/** Verifies the raw request and deduplicates before handing untrusted event data to a consumer. */
final readonly class IdempotentPaymentWebhookService
{
    public function __construct(
        private PaymentProviderRegistry $providers,
        private PaymentIdempotencyStore $idempotencyStore,
        private PaymentWebhookConsumer $consumer,
    ) {
    }

    public function process(string $provider, string $rawBody, ?string $signature): bool
    {
        if (strlen($rawBody) > 65_536) {
            throw new InvalidPaymentWebhook('Webhook payload exceeds the maximum size.');
        }

        try {
            $event = $this->providers->parseWebhook($provider, $rawBody, $signature);
        } catch (\JsonException|\UnexpectedValueException|\InvalidArgumentException $exception) {
            throw new InvalidPaymentWebhook('Webhook payload is invalid.', previous: $exception);
        } catch (UnknownPaymentProvider|PaymentProviderUnavailable $exception) {
            throw new InvalidPaymentWebhook('Webhook provider is invalid or not configured.', previous: $exception);
        }

        // Provider event IDs may only be unique within one merchant account.
        $key = hash('sha256', $provider.':'.($event->tenantId ?? $event->providerReference).':'.$event->providerReference.':'.$event->eventId);
        if (!$this->idempotencyStore->claim($key)) {
            return false;
        }

        try {
            $this->consumer->consume($event);
            $this->idempotencyStore->complete($key);
        } catch (\Throwable $exception) {
            try {
                $this->idempotencyStore->release($key);
            } catch (\Throwable) {
                // Preserve the processing failure; the durable claim lease must allow a later retry.
            }
            throw $exception;
        }

        return true;
    }
}
