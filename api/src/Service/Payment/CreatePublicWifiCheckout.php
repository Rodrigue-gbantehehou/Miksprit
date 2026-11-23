<?php

declare(strict_types=1);

namespace App\Service\Payment;

use App\Entity\PaymentAttempt;
use App\Entity\PlanSnapshot;
use App\Entity\WifiOrder;
use App\Repository\PublicCheckoutRepository;
use App\Service\Provider\Payment\CheckoutTokenUnavailable;

/** Creates a frozen public Wi-Fi order and starts its configured payment provider. */
final readonly class CreatePublicWifiCheckout
{
    public function __construct(
        private PublicCheckoutRepository $repository,
        private PaymentProviderRegistry $providers,
        private PaymentCheckoutSettings $settings,
        private TenantPaymentSettings $tenantPaymentSettings,
    ) {
    }

    public function execute(string $siteId, string $planId, PaymentCustomer $customer, string $idempotencyKey): PublicWifiCheckout
    {
        if (preg_match('/^[A-Za-z0-9_-]{16,128}$/D', $idempotencyKey) !== 1) throw new \InvalidArgumentException('Checkout idempotency key is invalid.');
        $keyHash = hash('sha256', $siteId."\0".$planId."\0".strtolower($customer->email)."\0".$idempotencyKey);
        $existing = $this->repository->findAttemptByCheckoutKeyHash($keyHash);
        if ($existing !== null) {
            if ($existing->state() === \App\Entity\PaymentState::Pending && $existing->providerReference() !== null) {
                if ($existing->checkoutUrl() !== null) {
                    return new PublicWifiCheckout($existing->orderReference, $existing->provider,
                        new PaymentSession($existing->providerReference(), $existing->checkoutUrl()));
                }
                $session = $this->providers->resumeCheckoutForTenant($existing->provider, $existing->tenantId->value, $existing->providerReference(), 'payment-attempt:'.$existing->id);
                $existing->attachCheckoutUrl($session->checkoutUrl);
                $this->repository->saveAttempt($existing);
                return new PublicWifiCheckout($existing->orderReference, $existing->provider, $session);
            }
            throw new PublicCheckoutInProgress($existing->orderReference);
        }
        $snapshot = $this->repository->findPurchaseablePlan($siteId, $planId);
        if (!$snapshot instanceof PlanSnapshot) throw new \DomainException('Published plan not found for this site.');
        $merchant = $this->tenantPaymentSettings->forTenant($snapshot->tenantId->value);
        if ($merchant === null || !$this->providers->canCheckoutForTenant($merchant['provider'], $snapshot->tenantId->value)) throw new PublicPaymentUnavailable();

        $orderReference = bin2hex(random_bytes(32));
        $order = new WifiOrder(bin2hex(random_bytes(16)), $orderReference, $snapshot->tenantId, $snapshot, new \DateTimeImmutable());
        $attempt = new PaymentAttempt(bin2hex(random_bytes(16)), $snapshot->tenantId, $orderReference,
            $merchant['provider'], $snapshot->price, checkoutRequestKeyHash: $keyHash);
        $this->repository->saveOrderAndAttempt($order, $attempt);

        try {
            $session = $this->providers->checkoutForTenant($attempt->provider, $snapshot->tenantId->value, new PaymentIntent(
                merchantReference: $orderReference,
                amountMinor: $snapshot->price->minorUnits,
                currency: 'XOF',
                description: $snapshot->name,
                returnUrl: $this->settings->returnUrlFor($orderReference, $snapshot->siteId),
                customer: $customer,
            ), 'payment-attempt:'.$attempt->id);
        } catch (CheckoutTokenUnavailable $exception) {
            $attempt->markPending($exception->providerReference);
            $this->repository->saveAttempt($attempt);
            throw new PublicCheckoutInProgress($orderReference, previous: $exception);
        }
        $attempt->markPending($session->providerReference, $session->checkoutUrl);
        $this->repository->saveAttempt($attempt);

        return new PublicWifiCheckout($orderReference, $attempt->provider, $session);
    }
}
