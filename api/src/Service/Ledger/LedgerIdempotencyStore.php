<?php

declare(strict_types=1);

namespace App\Service\Ledger;

use App\Entity\TenantContext;

interface LedgerIdempotencyStore
{
    /** Atomically claims a tenant/key/fingerprint in the current transaction; false means a prior claim exists. */
    public function claim(TenantContext $tenant, string $key, string $fingerprint): bool;

    /** Completes the claim in the same transaction as the ledger append. */
    public function complete(TenantContext $tenant, string $key, string $entryId): void;
}
