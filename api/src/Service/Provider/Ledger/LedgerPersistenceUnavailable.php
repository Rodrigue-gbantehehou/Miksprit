<?php

declare(strict_types=1);

namespace App\Service\Provider\Ledger;

final class LedgerPersistenceUnavailable extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Ledger posting requires durable tenant-scoped persistence and a transactional idempotency store.');
    }
}
