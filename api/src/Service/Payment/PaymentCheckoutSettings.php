<?php

declare(strict_types=1);

namespace App\Service\Payment;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Server-owned callback configuration; never populated from request data. */
final readonly class PaymentCheckoutSettings
{
    public function __construct(#[Autowire('%env(PAYMENT_RETURN_URL)%')] public string $returnUrl)
    {
        $parts = parse_url($returnUrl);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new \InvalidArgumentException('Payment return URL must be a configured HTTPS URL.');
        }
    }

    public function returnUrlFor(string $orderReference, string $siteId): string
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $orderReference) !== 1 || preg_match('/^[A-Za-z0-9_-]{8,80}$/D', $siteId) !== 1) {
            throw new \InvalidArgumentException('Payment return reference is invalid.');
        }
        $parts = parse_url($this->returnUrl);
        if (!is_array($parts)) throw new \LogicException('Payment return URL configuration is invalid.');
        parse_str((string) ($parts['query'] ?? ''), $query);
        $query['order_reference'] = $orderReference;
        $query['siteId'] = $siteId;
        $url = ($parts['scheme'] ?? 'https').'://'.$parts['host']
            .(isset($parts['port']) ? ':'.$parts['port'] : '')
            .($parts['path'] ?? '')
            .'?'.http_build_query($query);
        if (isset($parts['fragment'])) $url .= '#'.$parts['fragment'];
        return $url;
    }
}
