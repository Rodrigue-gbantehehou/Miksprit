<?php

declare(strict_types=1);

namespace App\Service\Payment;

use App\Entity\PaymentAttempt;
use App\Entity\PlanSnapshot;
use App\Entity\WifiOrder;
use App\Repository\PublicCheckoutRepository;
use App\Service\Provider\Payment\CheckoutTokenUnavailable;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Creates a frozen public Wi-Fi order and starts its configured payment provider. */
final readonly class CreatePublicWifiCheckout
{
    public function __construct(
        private PublicCheckoutRepository $repository,
        private PaymentProviderRegistry $providers,
        private PaymentCheckoutSettings $settings,
        private PlatformCommissionPolicy $commissionPolicy,
        #[Autowire('%env(PAYMENT_DEFAULT_PROVIDER)%')] private string $defaultProvider,
    ) {
    }

    public function execute(string $siteId, string $planId, PaymentCustomer $customer, string $idempotencyKey): PublicWifiCheckout
    {
        // Never accept a sale whose operator balance cannot later be calculated under an agreed policy.
        $commissionBasisPoints = $this->commissionPolicy->basisPoints();
        if (preg_match('/^[A-Za-z0-9_-]{16,128}$/D', $idempotencyKey) !== 1) throw new \InvalidArgumentException('Checkout idempotency key is invalid.');
        $keyHash = hash('sha256', $siteId."\0".$planId."\0".strtolower($customer->email)."\0".$idempotencyKey);
        $existing = $this->repository->findAttemptByCheckoutKeyHash($keyHash);
        if ($existing !== null) {
            if ($existing->state() === \App\Entity\PaymentState::Pending && $existing->providerReference() !== null) {
                if ($existing->checkoutUrl() !== null) {
                    return new PublicWifiCheckout($existing->orderReference, $existing->provider,
                        new PaymentSession($existing->providerReference(), $existing->checkoutUrl()));
                }
                $session = $this->providers->resumeCheckout($existing->provider, $existing->providerReference(), 'payment-attempt:'.$existing->id);
                $existing->attachCheckoutUrl($session->checkoutUrl);
                $this->repository->saveAttempt($existing);
                return new PublicWifiCheckout($existing->orderReference, $existing->provider, $session);
            }
            throw new PublicCheckoutInProgress($existing->orderReference);
        }
        if (!$this->providers->canCheckout($this->defaultProvider)) throw new PublicPaymentUnavailable();
        $snapshot = $this->repository->findPurchaseablePlan($siteId, $planId);
        if (!$snapshot instanceof PlanSnapshot) throw new \DomainException('Published plan not found for this site.');

        $orderReference = bin2hex(random_bytes(32));
        $order = new WifiOrder(bin2hex(random_bytes(16)), $orderReference, $snapshot->tenantId, $snapshot, new \DateTimeImmutable(), $commissionBasisPoints);
        $attempt = new PaymentAttempt(bin2hex(random_bytes(16)), $snapshot->tenantId, $orderReference,
            $this->defaultProvider, $snapshot->price, checkoutRequestKeyHash: $keyHash);
        $this->repository->saveOrderAndAttempt($order, $attempt);

        try {
            $session = $this->providers->checkout($attempt->provider, new PaymentIntent(
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
