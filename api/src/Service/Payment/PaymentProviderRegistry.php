<?php

declare(strict_types=1);

namespace App\Service\Payment;

use App\Entity\VerifiedPaymentStatus;
use App\Event\PaymentWebhookEvent;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final class PaymentProviderRegistry
{
    /** @var array<string, PaymentProviderAdapter> */
    private array $providers = [];

    /** @param iterable<PaymentProviderAdapter> $providers */
    public function __construct(#[AutowireIterator('miksprit.payment_provider')] iterable $providers)
    {
        foreach ($providers as $provider) {
            $code = strtolower(trim($provider->code()));
            if (preg_match('/^[a-z][a-z0-9_-]{1,39}$/D', $code) !== 1 || isset($this->providers[$code])) {
                throw new \LogicException('Payment provider codes must be valid and unique.');
            }
            $this->providers[$code] = $provider;
        }
    }

    public function checkout(string $code, PaymentIntent $intent, string $idempotencyKey): PaymentSession
    {
        return $this->provider($code)->createCheckout($intent, $idempotencyKey);
    }

    public function canCheckout(string $code): bool
    {
        return $this->provider($code)->canCreateCheckout();
    }

    public function resumeCheckout(string $code, string $providerReference, string $idempotencyKey): PaymentSession
    {
        return $this->provider($code)->resumeCheckout($providerReference, $idempotencyKey);
    }

    public function verify(string $code, string $providerReference): VerifiedPaymentStatus
    {
        $provider = $this->provider($code);
        return $provider->verify($providerReference);
    }

    public function webhookSignatureHeader(string $code): string
    {
        return $this->provider($code)->webhookSignatureHeader();
    }

    public function parseWebhook(string $code, string $rawBody, ?string $signature): PaymentWebhookEvent
    {
        $provider = $this->provider($code);
        if (!$provider->validateWebhook($rawBody, $signature)) {
            throw new \UnexpectedValueException('Invalid payment webhook signature.');
        }
        $event = $provider->parseWebhook($rawBody);
        if ($event->provider !== $code) {
            throw new \UnexpectedValueException('Webhook provider code does not match its adapter.');
        }
        return $event;
    }

    /** @return list<string> */
    public function codes(): array
    {
        return array_keys($this->providers);
    }

    private function provider(string $code): PaymentProviderAdapter
    {
        $provider = $this->providers[strtolower(trim($code))] ?? null;
        if (!$provider instanceof PaymentProviderAdapter) {
            throw new UnknownPaymentProvider();
        }
        return $provider;
    }
}
