<?php

declare(strict_types=1);

namespace App\Service\Provider\Ledger;

use App\Entity\TenantContext;
use App\Service\Ledger\LedgerIdempotencyStore;

final class UnavailableLedgerIdempotencyStore implements LedgerIdempotencyStore
{
    public function claim(TenantContext $tenant, string $key, string $fingerprint): bool
    {
        throw new LedgerPersistenceUnavailable();
    }

    public function complete(TenantContext $tenant, string $key, string $entryId): void
    {
        throw new LedgerPersistenceUnavailable();
    }
}
