<?php

declare(strict_types=1);

namespace App\Service\Provider\Payment;

/** Runtime settings supplied by the application composition root. Disabled by default. */
final readonly class FedaPayConfiguration
{
    public function __construct(
        public bool $enabled = false,
        public ?string $secretKey = null,
        public ?string $webhookSecret = null,
        public ?string $baseUrl = null,
        public bool $payoutsEnabled = false,
    ) {
    }

    public function canCreatePayments(): bool
    {
        $parts = $this->baseUrl !== null ? parse_url($this->baseUrl) : false;

        return $this->enabled
            && $this->secretKey !== null
            && trim($this->secretKey) !== ''
            && is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && in_array(strtolower((string) ($parts['host'] ?? '')), ['api.fedapay.com', 'sandbox-api.fedapay.com'], true)
            && ($parts['path'] ?? '') === '/v1'
            && !isset($parts['query'])
            && !isset($parts['fragment'])
            && !isset($parts['user'], $parts['pass']);
    }

    public function canValidateWebhooks(): bool
    {
        return $this->enabled
            && $this->webhookSecret !== null
            && trim($this->webhookSecret) !== '';
    }

    public function canSendPayouts(): bool
    {
        return $this->payoutsEnabled && $this->canCreatePayments();
    }
}
