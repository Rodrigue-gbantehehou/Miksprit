<?php

declare(strict_types=1);

namespace App\Repository;

use App\Service\Router\VoucherProvisioningIdempotencyStore;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(VoucherProvisioningIdempotencyStore::class)]
final readonly class DoctrineVoucherProvisioningIdempotencyStore implements VoucherProvisioningIdempotencyStore
{
    public function __construct(private Connection $connection)
    {
    }

    public function claim(string $key): bool
    {
        self::assertKey($key);
        try {
            $this->connection->insert('voucher_provisioning_idempotency', [
                'idempotency_key' => $key,
                'state' => 'claimed',
                'claimed_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);

            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    public function complete(string $key): void
    {
        self::assertKey($key);
        $this->connection->executeStatement(
            "UPDATE voucher_provisioning_idempotency SET state = 'completed', completed_at = COALESCE(completed_at, ?) WHERE idempotency_key = ? AND state IN ('claimed', 'completed')",
            [(new \DateTimeImmutable())->format('Y-m-d H:i:s'), $key],
        );

        if ($this->state($key) !== 'completed') {
            throw new \LogicException('Only a claimed voucher provisioning key can be completed.');
        }
    }

    public function release(string $key): void
    {
        self::assertKey($key);
        $this->connection->executeStatement(
            "DELETE FROM voucher_provisioning_idempotency WHERE idempotency_key = ? AND state = 'claimed'",
            [$key],
        );
    }

    private function state(string $key): ?string
    {
        $state = $this->connection->fetchOne(
            'SELECT state FROM voucher_provisioning_idempotency WHERE idempotency_key = ?',
            [$key],
        );

        return $state === false ? null : (string) $state;
    }

    private static function assertKey(string $key): void
    {
        if (trim($key) === '' || strlen($key) > 200) {
            throw new \InvalidArgumentException('Voucher provisioning idempotency key must be present and bounded.');
        }
    }
}
