<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Entity\AccountLinkPurpose;
use App\Repository\AccountActionTokenRepository;
use App\Repository\UserRepository;

final readonly class VerifyOperatorEmail
{
    public function __construct(
        private AccountActionTokenRepository $tokens,
        private UserRepository $users,
    ) {
    }

    public function complete(string $rawToken): bool
    {
        if (preg_match('/^[A-Za-z0-9_-]{43}$/D', $rawToken) !== 1) return false;

        $now = new \DateTimeImmutable();
        $token = $this->tokens->findUsable(hash('sha256', $rawToken), AccountLinkPurpose::EmailVerification, $now);
        if ($token === null) return false;
        $user = $this->users->findById($token->userId());
        if ($user === null || !$user->isActive()) return false;

        return $this->tokens->consumeAtomically($token, $now, function () use ($user, $now): void {
            $user->markEmailVerified($now);
            $this->users->save($user);
        });
    }
}
