<?php

declare(strict_types=1);

namespace App\Service\Provider\Ledger;

use App\Service\Ledger\LedgerTransactionManager;

final class UnavailableLedgerTransactionManager implements LedgerTransactionManager
{
    public function transactional(callable $operation): mixed
    {
        throw new LedgerPersistenceUnavailable();
    }
}
