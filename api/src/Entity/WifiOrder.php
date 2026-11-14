<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

use App\Entity\PlanSnapshot;
use App\Entity\PaymentAttempt;
use App\Entity\PaymentState;
use App\Entity\TenantId;
use App\Entity\TenantOwned;

/** Order aggregate freezes plan terms and gates every access/provisioning transition on verified payment. */
#[ORM\Entity]
#[ORM\Table(name: 'wifi_orders')]
#[ORM\UniqueConstraint(name: 'uniq_order_public_reference', columns: ['public_reference'])]
final class WifiOrder implements TenantOwned
{
    #[ORM\Column(name: 'state', enumType: OrderState::class, length: 24)]
    private OrderState $state = OrderState::PendingPayment;

    #[ORM\Column(name: 'voucher_reference', type: 'string', length: 200, nullable: true)]
    private ?string $voucherReference = null;

    #[ORM\Column(name: 'successful_payment_attempt_id', type: 'string', length: 80, nullable: true)]
    private ?string $successfulPaymentAttemptId = null;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 80)]
        public readonly string $id,
        #[ORM\Column(name: 'public_reference', type: 'string', length: 160)]
        public readonly string $publicReference,
        #[ORM\Embedded(class: TenantId::class, columnPrefix: false)]
        private readonly TenantId $ownerTenantId,
        #[ORM\Embedded(class: PlanSnapshot::class)]
        public readonly PlanSnapshot $plan,
        #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
        public readonly \DateTimeImmutable $createdAt,
    ) {
        if ($id === '' || $publicReference === '' || strlen($publicReference) > 160) {
            throw new \InvalidArgumentException('Order identifiers must be present and bounded.');
        }
        if (!$ownerTenantId->equals($plan->tenantId)) {
            throw new \InvalidArgumentException('Order and plan must belong to the same tenant.');
        }
    }

    public function tenantId(): TenantId
    {
        return $this->ownerTenantId;
    }

    public function state(): OrderState
    {
        return $this->state;
    }

    /** Returns true only when this call records the first successful payment attempt. */
    public function confirmPayment(PaymentAttempt $attempt): bool
    {
        if ($attempt->state() !== PaymentState::Succeeded
            || !$attempt->tenantId->equals($this->ownerTenantId)
            || $attempt->orderReference !== $this->publicReference
            || !$attempt->expectedAmount->equals($this->plan->price)) {
            throw new \DomainException('A matching verified payment is required for this order.');
        }

        if ($this->successfulPaymentAttemptId !== null) {
            if ($this->successfulPaymentAttemptId !== $attempt->id) {
                throw new \DomainException('A second successful payment requires reconciliation.');
            }

            return false;
        }
        if (!in_array($this->state, [OrderState::PendingPayment, OrderState::PaymentFailed], true)) {
            throw new \DomainException('Order cannot transition to paid from its current state.');
        }

        $this->state = OrderState::Paid;
        $this->successfulPaymentAttemptId = $attempt->id;

        return true;
    }

    public function markPaymentFailed(PaymentAttempt $attempt): void
    {
        if ($attempt->state() !== PaymentState::Failed
            || !$attempt->tenantId->equals($this->ownerTenantId)
            || $attempt->orderReference !== $this->publicReference
            || !$attempt->expectedAmount->equals($this->plan->price)) {
            throw new \DomainException('A matching verified failed payment is required for this order.');
        }
        if ($this->state !== OrderState::PendingPayment) {
            throw new \DomainException('Order cannot transition to payment failed from its current state.');
        }

        $this->state = OrderState::PaymentFailed;
    }

    public function markAccessPending(): void
    {
        if ($this->state !== OrderState::Paid && $this->state !== OrderState::ProvisioningFailed) {
            throw new \DomainException('Only a paid order can be queued for access provisioning.');
        }
        $this->state = OrderState::AccessPending;
    }

    public function fulfill(string $voucherReference): void
    {
        if (trim($voucherReference) === '' || strlen($voucherReference) > 200) {
            throw new \InvalidArgumentException('Voucher reference must be present and bounded.');
        }
        if ($this->state === OrderState::Fulfilled && $this->voucherReference === $voucherReference) {
            return;
        }
        if ($this->state !== OrderState::AccessPending) {
            throw new \DomainException('Only a paid order with provisioning in progress can be fulfilled.');
        }

        $this->voucherReference = $voucherReference;
        $this->state = OrderState::Fulfilled;
    }

    public function markProvisioningFailed(): void
    {
        if ($this->state !== OrderState::AccessPending) {
            throw new \DomainException('Only an order awaiting access can fail provisioning.');
        }
        $this->state = OrderState::ProvisioningFailed;
    }

    public function voucherReference(): ?string
    {
        return $this->voucherReference;
    }
}
