<?php

declare(strict_types=1);

namespace App\Service\Ledger;

use App\Entity\LedgerAccount;
use App\Entity\LedgerEntryType;

/** Validated facts for one balanced XOF posting; it does not initiate or transfer money. */
final readonly class LedgerPostingCommand
{
    public function __construct(
        public LedgerEntryType $entryType,
        public LedgerAccount $debitAccount,
        public LedgerAccount $creditAccount,
        public int $amountMinor,
        public string $currency,
        public string $sourceType,
        public string $sourceReference,
        public string $idempotencyKey,
        public \DateTimeImmutable $occurredAt,
    ) {
        foreach ([[$sourceType, 40], [$sourceReference, 200], [$idempotencyKey, 200]] as [$value, $maxLength]) {
            if (trim($value) === '' || strlen($value) > $maxLength || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
                throw new \InvalidArgumentException('Ledger source and idempotency identifiers must be present and bounded.');
            }
        }
        if ($amountMinor < 1 || $currency !== 'XOF') {
            throw new \InvalidArgumentException('Ledger postings require a positive integer XOF amount.');
        }
        if ($debitAccount === $creditAccount) {
            throw new \InvalidArgumentException('A balanced posting must debit and credit different accounts.');
        }
    }

    public function fingerprint(): string
    {
        return hash('sha256', implode("\0", [
            $this->entryType->value,
            $this->debitAccount->value,
            $this->creditAccount->value,
            (string) $this->amountMinor,
            $this->currency,
            $this->sourceType,
            $this->sourceReference,
            $this->occurredAt->format('Y-m-d\TH:i:s.uP'),
        ]));
    }
}
