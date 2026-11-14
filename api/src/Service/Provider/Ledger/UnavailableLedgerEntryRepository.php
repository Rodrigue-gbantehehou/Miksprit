<?php

declare(strict_types=1);

namespace App\Service\Provider\Ledger;

use App\Entity\LedgerEntry;
use App\Entity\TenantContext;
use App\Service\Ledger\LedgerEntryRepository;

final class UnavailableLedgerEntryRepository implements LedgerEntryRepository
{
    public function findByIdempotencyKey(TenantContext $tenant, string $idempotencyKey): ?LedgerEntry
    {
        throw new LedgerPersistenceUnavailable();
    }

    public function append(TenantContext $tenant, LedgerEntry $entry): void
    {
        throw new LedgerPersistenceUnavailable();
    }
}
