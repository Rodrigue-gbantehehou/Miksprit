<?php

declare(strict_types=1);

namespace App\Service\Provider\Payment;

final readonly class WebhookSignatureValidator
{
    public function __construct(
        private FedaPayConfiguration $configuration,
        private int $toleranceSeconds = 300,
    ) {
        if ($this->toleranceSeconds < 1 || $this->toleranceSeconds > 3600) {
            throw new \InvalidArgumentException('Webhook signature tolerance must be between 1 and 3600 seconds.');
        }
    }

    /** Validates FedaPay's t=<unix>,s=<hex> signature over "<timestamp>.<raw-body>". */
    public function isValid(string $rawBody, ?string $providedSignature): bool
    {
        if (!$this->configuration->canValidateWebhooks() || $providedSignature === null) {
            return false;
        }

        if (strlen($rawBody) > 65_536) {
            return false;
        }

        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $providedSignature) as $item) {
            $parts = explode('=', trim($item), 2);
            if (count($parts) !== 2) {
                continue;
            }
            if ($parts[0] === 't') {
                if ($timestamp !== null || strlen($parts[1]) > 12 || !ctype_digit($parts[1])) {
                    return false;
                }
                $timestamp = (int) $parts[1];
            } elseif ($parts[0] === 's') {
                $signatures[] = $parts[1];
            }
        }

        if ($timestamp === null || abs(time() - $timestamp) > $this->toleranceSeconds || $signatures === []) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$rawBody, $this->configuration->webhookSecret);
        $valid = false;
        foreach ($signatures as $signature) {
            if (preg_match('/^[a-fA-F0-9]{64}$/D', $signature) === 1) {
                $valid = hash_equals($expected, strtolower($signature)) || $valid;
            }
        }

        return $valid;
    }
}
