<?php

declare(strict_types=1);

namespace App\Service\Payment;

/** A checkout URL means payment is pending; it is not proof of payment. */
final readonly class PaymentSession
{
    public function __construct(public string $providerReference, public string $checkoutUrl)
    {
        $parts = parse_url($checkoutUrl);
        if (trim($providerReference) === '' || strlen($providerReference) > 200 || strlen($checkoutUrl) > 2048 || filter_var($checkoutUrl, FILTER_VALIDATE_URL) === false
            || !is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new \InvalidArgumentException('A provider reference and HTTPS checkout URL are required.');
        }
    }
}
