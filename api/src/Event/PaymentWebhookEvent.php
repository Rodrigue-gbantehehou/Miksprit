<?php

declare(strict_types=1);

namespace App\Event;

/** Normalized provider event; raw provider payloads stay in the infrastructure adapter. */
final readonly class PaymentWebhookEvent
{
    public function __construct(
        public string $eventId,
        public string $eventType,
        public string $providerReference,
        public string $provider = 'fedapay',
    ) {
        foreach ([$eventId, $eventType, $providerReference, $provider] as $value) {
            if (trim($value) === '' || strlen($value) > 200 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
                throw new \InvalidArgumentException('Webhook event identifiers must be present and bounded.');
            }
        }
    }
}
