<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TenantContext;
use App\Service\Ledger\LedgerIdempotencyStore;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(LedgerIdempotencyStore::class)]
final readonly class DoctrineLedgerIdempotencyStore implements LedgerIdempotencyStore
{
    public function __construct(private Connection $connection)
    {
    }

    public function claim(TenantContext $tenant, string $key, string $fingerprint): bool
    {
        try {
            $this->connection->insert('ledger_posting_idempotency', [
                'tenant_id' => $tenant->tenantId->value,
                'idempotency_key' => $key,
                'fingerprint' => $fingerprint,
                'state' => 'claimed',
                'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);
            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    public function complete(TenantContext $tenant, string $key, string $entryId): void
    {
        $updated = $this->connection->executeStatement(
            "UPDATE ledger_posting_idempotency SET state = 'completed', entry_id = ? WHERE tenant_id = ? AND idempotency_key = ? AND state = 'claimed'",
            [$entryId, $tenant->tenantId->value, $key],
        );
        if ($updated !== 1) {
            throw new \LogicException('Ledger idempotency claim could not be completed.');
        }
    }
}
