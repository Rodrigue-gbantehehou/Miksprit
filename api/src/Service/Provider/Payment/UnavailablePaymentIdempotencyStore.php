<?php

declare(strict_types=1);

namespace App\Service\Provider\Payment;


/** Never acknowledges an event unless durable, atomic deduplication is available. */
final class UnavailablePaymentIdempotencyStore implements PaymentIdempotencyStore
{
    public function claim(string $key): bool
    {
        throw new PaymentPersistenceUnavailable();
    }

    public function complete(string $key): void
    {
        throw new PaymentPersistenceUnavailable();
    }

    public function release(string $key): void
    {
        throw new PaymentPersistenceUnavailable();
    }
}
