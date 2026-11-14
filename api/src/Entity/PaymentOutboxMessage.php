<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Minimal durable work signal; contains only tenant, order reference, type, and idempotency metadata. */
#[ORM\Entity]
#[ORM\Table(name: 'payment_outbox')]
#[ORM\UniqueConstraint(name: 'uniq_payment_outbox_tenant_idempotency', columns: ['tenant_id', 'idempotency_key'])]
#[ORM\Index(name: 'idx_payment_outbox_pending', columns: ['state', 'created_at'])]
final class PaymentOutboxMessage
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 80)]
        public readonly string $id,
        #[ORM\Embedded(class: TenantId::class, columnPrefix: false)]
        public readonly TenantId $tenantId,
        #[ORM\Column(name: 'order_reference', type: 'string', length: 160)]
        public readonly string $orderReference,
        #[ORM\Column(name: 'message_type', enumType: PaymentOutboxMessageType::class, length: 32)]
        public readonly PaymentOutboxMessageType $messageType,
        #[ORM\Column(name: 'idempotency_key', type: 'string', length: 200)]
        public readonly string $idempotencyKey,
        #[ORM\Column(enumType: PaymentOutboxMessageState::class, length: 16)]
        private PaymentOutboxMessageState $state,
        #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
        public readonly \DateTimeImmutable $createdAt,
    ) {
        foreach ([$id, $orderReference, $idempotencyKey] as $value) {
            if (trim($value) === '' || strlen($value) > 200 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
                throw new \InvalidArgumentException('Payment outbox identifiers must be present and bounded.');
            }
        }
        if (strlen($id) > 80 || strlen($orderReference) > 160 || $state !== PaymentOutboxMessageState::Pending) {
            throw new \InvalidArgumentException('Invalid initial payment outbox message state or identifier.');
        }
    }

    public function state(): PaymentOutboxMessageState { return $this->state; }

    public function markCompleted(): void
    {
        if ($this->state !== PaymentOutboxMessageState::Processing) throw new \DomainException('Only a claimed outbox message can complete.');
        $this->state = PaymentOutboxMessageState::Completed;
    }

    public function markFailed(): void
    {
        if ($this->state !== PaymentOutboxMessageState::Processing) throw new \DomainException('Only a claimed outbox message can fail.');
        $this->state = PaymentOutboxMessageState::Failed;
    }

    public function releaseForRetry(): void
    {
        if ($this->state !== PaymentOutboxMessageState::Processing) throw new \DomainException('Only a claimed outbox message can be retried.');
        $this->state = PaymentOutboxMessageState::Pending;
    }
}
