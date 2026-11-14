<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Doctrine mapping for the DBAL-managed atomic ledger claim table. */
#[ORM\Entity]
#[ORM\Table(name: 'ledger_posting_idempotency')]
final class LedgerPostingIdempotencyRecord
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(name: 'tenant_id', type: 'string', length: 80)]
        public readonly string $tenantId,
        #[ORM\Id]
        #[ORM\Column(name: 'idempotency_key', type: 'string', length: 200)]
        public readonly string $idempotencyKey,
        #[ORM\Column(type: 'string', length: 64)]
        public readonly string $fingerprint,
        #[ORM\Column(type: 'string', length: 16)]
        public readonly string $state,
        #[ORM\Column(name: 'entry_id', type: 'string', length: 80, nullable: true)]
        public readonly ?string $entryId,
        #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
        public readonly \DateTimeImmutable $createdAt,
    ) {
    }
}
