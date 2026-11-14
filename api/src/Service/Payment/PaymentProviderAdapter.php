<?php

declare(strict_types=1);

namespace App\Service\Payment;

use App\Entity\VerifiedPaymentStatus;
use App\Event\PaymentWebhookEvent;

/** A payment plugin owns checkout, server-side status verification and webhook normalization. */
interface PaymentProviderAdapter extends CheckoutProvider
{
    public function code(): string;

    public function canCreateCheckout(): bool;

    /** Recreate a hosted session for an existing transaction without creating another charge. */
    public function resumeCheckout(string $providerReference, string $idempotencyKey): PaymentSession;

    public function webhookSignatureHeader(): string;

    public function verify(string $providerReference): VerifiedPaymentStatus;

    public function validateWebhook(string $rawBody, ?string $signature): bool;

    public function parseWebhook(string $rawBody): PaymentWebhookEvent;
}
