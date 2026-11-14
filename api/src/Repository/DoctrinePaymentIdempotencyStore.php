<?php

declare(strict_types=1);

namespace App\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(PaymentIdempotencyStore::class)]
final readonly class DoctrinePaymentIdempotencyStore implements PaymentIdempotencyStore
{
    private const LEASE_SECONDS = 900;

    public function __construct(private Connection $connection)
    {
    }

    public function claim(string $key): bool
    {
        if ($key === '' || strlen($key) > 200) {
            throw new \InvalidArgumentException('Payment webhook idempotency key is invalid.');
        }
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        try {
            $this->connection->insert('payment_webhook_inbox', [
                'idempotency_key' => $key,
                'state' => 'claimed',
                'claimed_at' => $now->format('Y-m-d H:i:s'),
                'completed_at' => null,
            ]);
            return true;
        } catch (UniqueConstraintViolationException) {
            $cutoff = $now->modify('-'.self::LEASE_SECONDS.' seconds');
            return $this->connection->executeStatement(
                "UPDATE payment_webhook_inbox SET state = 'claimed', claimed_at = ?, completed_at = NULL WHERE idempotency_key = ? AND state = 'claimed' AND claimed_at < ?",
                [$now->format('Y-m-d H:i:s'), $key, $cutoff->format('Y-m-d H:i:s')],
            ) === 1;
        }
    }

    public function complete(string $key): void
    {
        $this->connection->executeStatement(
            "UPDATE payment_webhook_inbox SET state = 'completed', completed_at = COALESCE(completed_at, ?) WHERE idempotency_key = ? AND state IN ('claimed', 'completed')",
            [(new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'), $key],
        );
    }

    public function release(string $key): void
    {
        $this->connection->executeStatement("DELETE FROM payment_webhook_inbox WHERE idempotency_key = ? AND state = 'claimed'", [$key]);
    }
}
