<?php

declare(strict_types=1);

namespace App\Service\Ledger;

use App\Entity\LedgerEntry;
use App\Entity\TenantContext;

/** Tenant-scoped, append-only ledger repository port. */
interface LedgerEntryRepository
{
    /** Query must include tenant_id and the idempotency key in SQL. */
    public function findByIdempotencyKey(TenantContext $tenant, string $idempotencyKey): ?LedgerEntry;

    /** Insert only; implementations must not expose update or delete operations. */
    public function append(TenantContext $tenant, LedgerEntry $entry): void;
}
