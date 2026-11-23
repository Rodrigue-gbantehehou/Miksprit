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
    public function __construct(#[AutowireIterator('miksprit.payment_provider')] iterable $providers, private TenantPaymentSettings $tenantSettings)
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

    public function canCheckoutForTenant(string $code, string $tenantId): bool
    {
        $provider = $this->provider($code);
        $settings = $this->tenantSettings->forTenant($tenantId);
        if ($settings === null || $settings['provider'] !== strtolower($code)) return false;
        if (!$provider instanceof TenantPaymentProviderAdapter) return false;
        return $provider->canCreateCheckoutForTenant(['apiKey' => $settings['apiKey'], 'webhookSecret' => $settings['webhookSecret'], 'environment' => $settings['environment']]);
    }

    public function tenantFeatureEnabled(string $code): bool
    {
        $provider = $this->provider($code);
        return $provider instanceof TenantPaymentProviderAdapter && $provider->tenantFeatureEnabled();
    }

    /** @param array{apiKey: string, webhookSecret: string, environment: string} $credentials */
    public function testTenantCredentials(string $code, array $credentials): void
    {
        $provider = $this->provider($code);
        if (!$provider instanceof TenantPaymentProviderAdapter) throw new UnknownPaymentProvider();
        $provider->testTenantCredentials($credentials);
    }

    public function checkoutForTenant(string $code, string $tenantId, PaymentIntent $intent, string $idempotencyKey): PaymentSession
    {
        $provider = $this->provider($code);
        $settings = $this->tenantSettings->forTenant($tenantId);
        if ($settings === null || $settings['provider'] !== strtolower($code) || !$provider instanceof TenantPaymentProviderAdapter) {
            throw new PublicPaymentUnavailable('The tenant payment provider is not configured.');
        }
        return $provider->createTenantCheckout($intent, $idempotencyKey, ['apiKey' => $settings['apiKey'], 'webhookSecret' => $settings['webhookSecret'], 'environment' => $settings['environment']]);
    }

    public function resumeCheckoutForTenant(string $code, string $tenantId, string $providerReference, string $idempotencyKey): PaymentSession
    {
        $provider = $this->provider($code);
        $settings = $this->tenantSettings->forTenant($tenantId);
        if ($settings === null || $settings['provider'] !== strtolower($code) || !$provider instanceof TenantPaymentProviderAdapter) {
            throw new PublicPaymentUnavailable('The tenant payment provider is not configured.');
        }
        return $provider->resumeTenantCheckout($providerReference, $idempotencyKey, ['apiKey' => $settings['apiKey'], 'webhookSecret' => $settings['webhookSecret'], 'environment' => $settings['environment']]);
    }

    public function resumeCheckout(string $code, string $providerReference, string $idempotencyKey): PaymentSession
    {
        return $this->provider($code)->resumeCheckout($providerReference, $idempotencyKey);
    }

    public function verify(string $code, string $providerReference, ?string $tenantId = null): VerifiedPaymentStatus
    {
        $provider = $this->provider($code);
        if ($provider instanceof TenantPaymentProviderAdapter) return $provider->verifyTenantPayment($providerReference, $tenantId);
        return $provider->verify($providerReference);
    }

    /** Verify an old centrally-collected sale using the retained platform account credentials. */
    public function verifyLegacy(string $code, string $providerReference): VerifiedPaymentStatus
    {
        return $this->provider($code)->verify($providerReference);
    }

    public function webhookSignatureHeader(string $code): string
    {
        return $this->provider($code)->webhookSignatureHeader();
    }

    public function parseWebhook(string $code, string $rawBody, ?string $signature): PaymentWebhookEvent
    {
        $provider = $this->provider($code);
        if ($provider instanceof TenantPaymentProviderAdapter) {
            $event = $provider->parseTenantWebhook($rawBody, $signature);
            if ($event->provider !== $code) throw new \UnexpectedValueException('Webhook provider code does not match its adapter.');
            return $event;
        }
        if (!$provider->validateWebhook($rawBody, $signature)) {
            throw new \UnexpectedValueException('Invalid payment webhook signature.');
        }
        $event = $provider->parseWebhook($rawBody);
        if ($event->provider !== $code) {
            throw new \UnexpectedValueException('Webhook provider code does not match its adapter.');
        }
        return $event;
    }

    /** Validate platform-owned subscription events with the platform account's webhook secret. */
    public function parsePlatformWebhook(string $code, string $rawBody, ?string $signature): PaymentWebhookEvent
    {
        $provider = $this->provider($code);
        if (!$provider->validateWebhook($rawBody, $signature)) throw new \UnexpectedValueException('Invalid platform payment webhook signature.');
        $event = $provider->parseWebhook($rawBody);
        if ($event->provider !== $code) throw new \UnexpectedValueException('Platform webhook provider code does not match its adapter.');
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
