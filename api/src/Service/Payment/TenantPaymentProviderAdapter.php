<?php

declare(strict_types=1);

namespace App\Service\Payment;

use App\Entity\VerifiedPaymentStatus;
use App\Event\PaymentWebhookEvent;

/** Adapter contract for providers whose merchant credentials belong to each tenant. */
interface TenantPaymentProviderAdapter extends PaymentProviderAdapter
{
    public function tenantFeatureEnabled(): bool;

    /** @param array{apiKey: string, webhookSecret: string, environment: string} $credentials */
    public function canCreateCheckoutForTenant(array $credentials): bool;

    /** Perform a non-mutating credential check against the provider. @param array{apiKey: string, webhookSecret: string, environment: string} $credentials */
    public function testTenantCredentials(array $credentials): void;

    /** @param array{apiKey: string, webhookSecret: string, environment: string} $credentials */
    public function createTenantCheckout(PaymentIntent $intent, string $idempotencyKey, array $credentials): PaymentSession;

    /** @param array{apiKey: string, webhookSecret: string, environment: string} $credentials */
    public function resumeTenantCheckout(string $providerReference, string $idempotencyKey, array $credentials): PaymentSession;

    /** Resolves the tenant from a persisted provider reference and verifies against its merchant credentials. */
    public function verifyTenantPayment(string $providerReference, ?string $tenantId = null): VerifiedPaymentStatus;

    /** Parses the event reference, resolves its tenant and authenticates with that merchant's webhook secret. */
    public function parseTenantWebhook(string $rawBody, ?string $signature): PaymentWebhookEvent;
}
