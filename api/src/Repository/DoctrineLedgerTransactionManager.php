<?php

declare(strict_types=1);

namespace App\Repository;

use App\Service\Ledger\LedgerTransactionManager;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(LedgerTransactionManager::class)]
final readonly class DoctrineLedgerTransactionManager implements LedgerTransactionManager
{
    public function __construct(private Connection $connection)
    {
    }

    public function transactional(callable $operation): mixed
    {
        return $this->connection->transactional($operation);
    }
}
