<?php

declare(strict_types=1);

namespace App\Service\Ledger;

use App\Entity\LedgerEntry;
use App\Entity\TenantContext;

/** Posts a balanced memo/accounting entry atomically; it never moves or pays out funds. */
final readonly class PostLedgerEntry
{
    public function __construct(
        private LedgerEntryRepository $entries,
        private LedgerTransactionManager $transactions,
        private LedgerIdempotencyStore $idempotency,
        private LedgerPostingRules $rules,
    ) {
    }

    public function execute(TenantContext $tenant, LedgerPostingCommand $command): LedgerEntry
    {
        $this->rules->assertAllowed($command->entryType, $command->debitAccount, $command->creditAccount);

        return $this->transactions->transactional(function () use ($tenant, $command): LedgerEntry {
            $existing = $this->entries->findByIdempotencyKey($tenant, $command->idempotencyKey);
            if ($existing !== null) {
                return $this->returnMatchingReplay($tenant, $command, $existing);
            }

            if (!$this->idempotency->claim($tenant, $command->idempotencyKey, $command->fingerprint())) {
                $existing = $this->entries->findByIdempotencyKey($tenant, $command->idempotencyKey);
                if ($existing !== null) {
                    return $this->returnMatchingReplay($tenant, $command, $existing);
                }

                throw new LedgerPostingInProgress('A posting with this tenant idempotency key is already being committed.');
            }

            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $entryId = 'ledger_'.bin2hex(random_bytes(16));
            $entry = new LedgerEntry(
                id: $entryId,
                ownerTenantId: $tenant->tenantId,
                journalId: $this->journalId($tenant, $command),
                entryType: $command->entryType,
                debitAccount: $command->debitAccount,
                creditAccount: $command->creditAccount,
                amountMinor: $command->amountMinor,
                currency: $command->currency,
                sourceType: $command->sourceType,
                sourceReference: $command->sourceReference,
                idempotencyKey: $command->idempotencyKey,
                occurredAt: $command->occurredAt,
                recordedAt: $now,
            );

            $this->entries->append($tenant, $entry);
            $this->idempotency->complete($tenant, $command->idempotencyKey, $entryId);

            return $entry;
        });
    }

    private function returnMatchingReplay(TenantContext $tenant, LedgerPostingCommand $command, LedgerEntry $entry): LedgerEntry
    {
        if (!$entry->tenantId()->equals($tenant->tenantId)) {
            throw new LedgerPostingConflict('Ledger idempotency result is not owned by the current tenant.');
        }
        if ($entry->entryType !== $command->entryType
            || $entry->debitAccount !== $command->debitAccount
            || $entry->creditAccount !== $command->creditAccount
            || $entry->amountMinor !== $command->amountMinor
            || $entry->currency !== $command->currency
            || $entry->sourceType !== $command->sourceType
            || $entry->sourceReference !== $command->sourceReference
            || $entry->idempotencyKey !== $command->idempotencyKey
            || $entry->occurredAt != $command->occurredAt) {
            throw new LedgerPostingConflict('Idempotency key was already used for a different ledger posting.');
        }

        return $entry;
    }

    private function journalId(TenantContext $tenant, LedgerPostingCommand $command): string
    {
        $hash = hash('sha256', implode("\0", [
            $tenant->tenantId->value,
            $command->sourceType,
            $command->sourceReference,
        ]));

        return 'journal_'.$hash;
    }
}
