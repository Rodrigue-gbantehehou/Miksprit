<?php

declare(strict_types=1);

namespace App\Service\Provider\Payment;

use App\Event\PaymentWebhookEvent;

/** Maps FedaPay's wire payload to the provider-neutral application event. */
final class FedaPayWebhookParser
{
    /** @throws \JsonException */
    public static function parse(string $rawBody): PaymentWebhookEvent
    {
        $payload = json_decode($rawBody, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) {
            throw new \UnexpectedValueException('Webhook payload must be a JSON object.');
        }
        $eventId = $payload['id'] ?? null;
        $eventType = $payload['type'] ?? $payload['name'] ?? null;
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $dataObject = is_array($data['object'] ?? null) ? $data['object'] : [];
        $providerReference = $payload['object_id'] ?? $data['id'] ?? $dataObject['id'] ?? null;
        if ((!is_string($eventId) && !is_int($eventId))
            || !is_string($eventType)
            || (!is_string($providerReference) && !is_int($providerReference))
            || (is_int($providerReference) && $providerReference < 1)) {
            throw new \UnexpectedValueException('Webhook payload is missing required identifiers.');
        }

        return new PaymentWebhookEvent((string) $eventId, $eventType, (string) $providerReference, 'fedapay');
    }
}
