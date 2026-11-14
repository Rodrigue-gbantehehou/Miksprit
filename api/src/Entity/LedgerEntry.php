<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Immutable, balanced double-entry posting. Each row debits and credits the same positive XOF amount.
 * Indicative entry types are analytical estimates, not money held, owed for payout, or transferred by Miksprit.
 */
#[ORM\Entity]
#[ORM\Table(name: 'ledger_entries')]
#[ORM\UniqueConstraint(name: 'uniq_ledger_tenant_idempotency', columns: ['tenant_id', 'idempotency_key'])]
#[ORM\UniqueConstraint(name: 'uniq_ledger_source_posting', columns: ['tenant_id', 'source_type', 'source_reference', 'entry_type'])]
#[ORM\Index(name: 'idx_ledger_journal', columns: ['tenant_id', 'journal_id'])]
#[ORM\Index(name: 'idx_ledger_occurred', columns: ['tenant_id', 'occurred_at'])]
final class LedgerEntry implements TenantOwned
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 80)]
        public readonly string $id,
        #[ORM\Embedded(class: TenantId::class, columnPrefix: false)]
        private readonly TenantId $ownerTenantId,
        #[ORM\Column(name: 'journal_id', type: 'string', length: 80)]
        public readonly string $journalId,
        #[ORM\Column(name: 'entry_type', enumType: LedgerEntryType::class, length: 40)]
        public readonly LedgerEntryType $entryType,
        #[ORM\Column(name: 'debit_account', enumType: LedgerAccount::class, length: 40)]
        public readonly LedgerAccount $debitAccount,
        #[ORM\Column(name: 'credit_account', enumType: LedgerAccount::class, length: 40)]
        public readonly LedgerAccount $creditAccount,
        #[ORM\Column(name: 'amount_minor', type: 'integer')]
        public readonly int $amountMinor,
        #[ORM\Column(type: 'string', length: 3)]
        public readonly string $currency,
        #[ORM\Column(name: 'source_type', type: 'string', length: 40)]
        public readonly string $sourceType,
        #[ORM\Column(name: 'source_reference', type: 'string', length: 200)]
        public readonly string $sourceReference,
        #[ORM\Column(name: 'idempotency_key', type: 'string', length: 200)]
        public readonly string $idempotencyKey,
        #[ORM\Column(name: 'occurred_at', type: 'datetime_immutable')]
        public readonly \DateTimeImmutable $occurredAt,
        #[ORM\Column(name: 'recorded_at', type: 'datetime_immutable')]
        public readonly \DateTimeImmutable $recordedAt,
    ) {
        foreach ([$id, $journalId, $sourceType, $sourceReference, $idempotencyKey] as $value) {
            if (trim($value) === '' || strlen($value) > 200 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
                throw new \InvalidArgumentException('Ledger identifiers must be present, bounded, and free of control characters.');
            }
        }
        if ($amountMinor < 1) {
            throw new \InvalidArgumentException('Ledger postings require a positive integer amount.');
        }
        if ($currency !== 'XOF') {
            throw new \InvalidArgumentException('The ledger currently supports XOF only.');
        }
        if ($debitAccount === $creditAccount) {
            throw new \InvalidArgumentException('A balanced posting must debit and credit different accounts.');
        }
    }

    public function tenantId(): TenantId
    {
        return $this->ownerTenantId;
    }

    public function isIndicative(): bool
    {
        return $this->entryType->isIndicative();
    }

    /** Both sides carry this exact amount, so this posting balances by construction. */
    public function isBalanced(): bool
    {
        return $this->amountMinor > 0 && $this->currency === 'XOF';
    }
}
