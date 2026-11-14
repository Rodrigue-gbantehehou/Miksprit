<?php

declare(strict_types=1);

namespace App\Service\Ledger;

interface LedgerTransactionManager
{
    /** Repository and idempotency adapters must participate in this same durable database transaction. */
    public function transactional(callable $operation): mixed;
}
