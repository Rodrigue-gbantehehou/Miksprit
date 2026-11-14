<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AccountActionToken;
use App\Entity\AccountLinkPurpose;

interface AccountActionTokenRepository
{
    public function invalidateOutstanding(string $userId, AccountLinkPurpose $purpose, \DateTimeImmutable $now): void;

    public function save(AccountActionToken $token): void;

    public function findUsable(string $tokenHash, AccountLinkPurpose $purpose, \DateTimeImmutable $now): ?AccountActionToken;

    public function consumeAtomically(AccountActionToken $token, \DateTimeImmutable $now, callable $operation): bool;
}
