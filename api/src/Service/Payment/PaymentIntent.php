<?php

declare(strict_types=1);

namespace App\Service\Payment;

final readonly class PaymentIntent
{
    public function __construct(
        public string $merchantReference,
        public int $amountMinor,
        public string $currency,
        public string $description,
        public string $returnUrl,
        public ?PaymentCustomer $customer = null,
    ) {
        if (trim($merchantReference) === '' || $amountMinor < 1 || $currency !== 'XOF' || trim($description) === '') {
            throw new \InvalidArgumentException('Payment intent requires a reference, positive XOF amount, and description.');
        }
        $parts = parse_url($returnUrl);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new \InvalidArgumentException('Payment return URL must use HTTPS.');
        }
    }
}
