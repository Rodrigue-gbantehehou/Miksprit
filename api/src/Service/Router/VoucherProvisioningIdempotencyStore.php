<?php

declare(strict_types=1);

namespace App\Service\Router;

interface VoucherProvisioningIdempotencyStore
{
    /** Atomically reserves the voucher key, returning false if it is already claimed or completed. */
    public function claim(string $key): bool;

    public function complete(string $key): void;

    /** Release only after a confirmed router failure; never release after a router success. */
    public function release(string $key): void;
}
