<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PendingOperatorRegistration;

interface PendingOperatorRegistrationRepository
{
    public function invalidateOutstandingForEmail(string $email, \DateTimeImmutable $now): void;

    public function save(PendingOperatorRegistration $registration): void;

    public function findUsable(string $tokenHash, \DateTimeImmutable $now): ?PendingOperatorRegistration;

    /** The callback runs once under a row lock and in the same transaction as token consumption. */
    public function consumeAtomically(PendingOperatorRegistration $registration, \DateTimeImmutable $now, callable $operation): bool;
}
