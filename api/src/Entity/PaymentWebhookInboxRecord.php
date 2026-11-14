<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Doctrine mapping for the DBAL-managed durable webhook deduplication table. */
#[ORM\Entity]
#[ORM\Table(name: 'payment_webhook_inbox')]
final class PaymentWebhookInboxRecord
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(name: 'idempotency_key', type: 'string', length: 80)]
        public readonly string $idempotencyKey,
        #[ORM\Column(type: 'string', length: 16)]
        public readonly string $state,
        #[ORM\Column(name: 'claimed_at', type: 'datetime_immutable')]
        public readonly \DateTimeImmutable $claimedAt,
        #[ORM\Column(name: 'completed_at', type: 'datetime_immutable', nullable: true)]
        public readonly ?\DateTimeImmutable $completedAt,
    ) {
    }
}
