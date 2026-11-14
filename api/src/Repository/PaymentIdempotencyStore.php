<?php

declare(strict_types=1);

namespace App\Repository;

/** Persistence adapter must implement claim atomically across concurrent workers. */
interface PaymentIdempotencyStore
{
    /**
     * Returns true only for the first successful atomic claim of this key.
     * Implementations must use a durable unique constraint and reclaim abandoned claims after a bounded lease.
     */
    public function claim(string $key): bool;

    /** Records that processing finished; repeated completion must be harmless. */
    public function complete(string $key): void;

    /** Releases an unfinished claim after a retryable processing failure. */
    public function release(string $key): void;
}
