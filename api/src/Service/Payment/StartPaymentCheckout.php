<?php

declare(strict_types=1);

namespace App\Service\Payment;

use App\Entity\PaymentAttempt;
use App\Entity\PaymentState;
use App\Entity\TenantContext;
use App\Repository\PaymentAttemptRepository;

/** Application use case: load a tenant-owned attempt, open provider checkout, then persist pending state. */
final readonly class StartPaymentCheckout
{
    public function __construct(
        private PaymentAttemptRepository $attempts,
        private PaymentProviderRegistry $providers,
        private PaymentCheckoutSettings $settings,
    ) {
    }

    public function execute(TenantContext $tenant, string $attemptId, string $description, ?PaymentCustomer $customer = null): PaymentSession
    {
        $attempt = $this->attempts->findForTenant($tenant, $attemptId);
        if ($attempt === null || !$attempt->tenantId->equals($tenant->tenantId)) {
            throw new \DomainException('Payment attempt not found.');
        }
        if ($attempt->state() !== PaymentState::Initiated) {
            throw new \DomainException('Payment checkout can only start for an initiated attempt.');
        }

        $session = $this->providers->checkout(
            $attempt->provider,
            new PaymentIntent(
                merchantReference: $attempt->orderReference,
                amountMinor: $attempt->expectedAmount->minorUnits,
                currency: $attempt->currency,
                description: $description,
                returnUrl: $this->settings->returnUrl,
                customer: $customer,
            ),
            idempotencyKey: 'payment-attempt:'.$attempt->id,
        );

        $attempt->markPending($session->providerReference, $session->checkoutUrl);
        $this->attempts->save($tenant, $attempt);

        return $session;
    }
}
