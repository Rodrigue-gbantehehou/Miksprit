<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

use App\Entity\XofAmount;
use App\Entity\TenantId;

#[ORM\Entity]
#[ORM\Table(name: 'payment_attempts')]
#[ORM\UniqueConstraint(name: 'uniq_tenant_provider_transaction', columns: ['tenant_id', 'provider', 'provider_reference'])]
#[ORM\UniqueConstraint(name: 'uniq_payment_checkout_request', columns: ['checkout_request_key_hash'])]
final class PaymentAttempt
{
    #[ORM\Column(name: 'state', enumType: PaymentState::class, length: 16)]
    private PaymentState $state = PaymentState::Initiated;

    #[ORM\Column(name: 'provider_reference', type: 'string', length: 200, nullable: true)]
    private ?string $providerReference = null;

    #[ORM\Column(name: 'checkout_url', type: 'text', nullable: true)]
    private ?string $checkoutUrl = null;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 80)]
        public readonly string $id,
        #[ORM\Embedded(class: TenantId::class, columnPrefix: false)]
        public readonly TenantId $tenantId,
        #[ORM\Column(name: 'order_reference', type: 'string', length: 200)]
        public readonly string $orderReference,
        #[ORM\Column(type: 'string', length: 40)]
        public readonly string $provider,
        #[ORM\Embedded(class: XofAmount::class, columnPrefix: 'expected_')]
        public readonly XofAmount $expectedAmount,
        #[ORM\Column(type: 'string', length: 3)]
        public readonly string $currency = 'XOF',
        #[ORM\Column(name: 'checkout_request_key_hash', type: 'string', length: 64, nullable: true)]
        public readonly ?string $checkoutRequestKeyHash = null,
    ) {
        foreach ([$id, $orderReference, $provider] as $value) {
            if ($value === '' || strlen($value) > 200) {
                throw new \InvalidArgumentException('Payment identifiers must be present and bounded.');
            }
        }
        if ($checkoutRequestKeyHash !== null && preg_match('/^[a-f0-9]{64}$/D', $checkoutRequestKeyHash) !== 1) {
            throw new \InvalidArgumentException('Checkout request key hash is invalid.');
        }
        if ($currency !== 'XOF') {
            throw new \InvalidArgumentException('Only XOF is supported.');
        }
        if ($expectedAmount->minorUnits < 1) {
            throw new \InvalidArgumentException('Payment amount must be positive.');
        }
    }

    public function state(): PaymentState
    {
        return $this->state;
    }

    public function providerReference(): ?string
    {
        return $this->providerReference;
    }

    public function markPending(string $providerReference, ?string $checkoutUrl = null): void
    {
        if ($this->state !== PaymentState::Initiated || trim($providerReference) === '' || strlen($providerReference) > 200) {
            throw new \DomainException('Only an initiated payment can become pending.');
        }
        $this->providerReference = $providerReference;
        if ($checkoutUrl !== null) {
            $parts = parse_url($checkoutUrl);
            if (strlen($checkoutUrl) > 2048 || !is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || !isset($parts['host']) || isset($parts['user'], $parts['pass'])) {
                throw new \InvalidArgumentException('Payment checkout URL must use HTTPS.');
            }
            $this->checkoutUrl = $checkoutUrl;
        }
        $this->state = PaymentState::Pending;
    }

    public function checkoutUrl(): ?string
    {
        return $this->checkoutUrl;
    }

    public function attachCheckoutUrl(string $checkoutUrl): void
    {
        $parts = parse_url($checkoutUrl);
        if ($this->state !== PaymentState::Pending || $this->providerReference === null || $this->checkoutUrl !== null
            || strlen($checkoutUrl) > 2048 || !is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || !isset($parts['host']) || isset($parts['user'], $parts['pass'])) {
            throw new \DomainException('A hosted URL can only be attached once to a pending payment.');
        }
        $this->checkoutUrl = $checkoutUrl;
    }

    /** Transition to succeeded only after verified server-to-server provider evidence matches this attempt. */
    public function applyVerifiedStatus(VerifiedPaymentStatus $status): void
    {
        if ($status->provider !== $this->provider
            || $this->providerReference === null
            || $status->providerReference !== $this->providerReference
            || $status->merchantReference !== $this->orderReference
            || !$status->amount->equals($this->expectedAmount)
            || $status->currency !== $this->currency) {
            throw new \DomainException('Verified payment does not match the expected transaction.');
        }

        if (!in_array($this->state, [PaymentState::Initiated, PaymentState::Pending], true)) {
            if ($this->state === $status->state
                && in_array($status->state, [PaymentState::Succeeded, PaymentState::Failed, PaymentState::Expired], true)) {
                return; // Idempotent duplicate notification, after matching evidence was checked.
            }
            throw new \DomainException('Terminal payment state cannot be changed.');
        }

        if (!in_array($status->state, [PaymentState::Succeeded, PaymentState::Failed, PaymentState::Expired, PaymentState::Pending], true)) {
            throw new \DomainException('Unsupported provider state.');
        }

        $this->state = $status->state;
    }
}
