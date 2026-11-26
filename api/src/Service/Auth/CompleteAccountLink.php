<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Entity\AccountLinkPurpose;
use App\Repository\AccountActionTokenRepository;
use App\Repository\MembershipRepository;
use App\Repository\UserRepository;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final readonly class CompleteAccountLink
{
    public function __construct(
        private AccountActionTokenRepository $tokens,
        private UserRepository $users,
        private MembershipRepository $memberships,
        private UserPasswordHasherInterface $hasher,
        private RefreshSessionStore $refreshSessions,
    ) {}

    public function complete(string $rawToken, string $rawPurpose, string $plainPassword): bool
    {
        $purpose = AccountLinkPurpose::tryFrom($rawPurpose);
        if (!in_array($purpose, [AccountLinkPurpose::PasswordReset, AccountLinkPurpose::Invitation], true)
            || preg_match('/^[A-Za-z0-9_-]{43}$/D', $rawToken) !== 1) {
            return false;
        }
        $now = new \DateTimeImmutable();
        $token = $this->tokens->findUsable(hash('sha256', $rawToken), $purpose, $now);
        if ($token === null) {
            return false;
        }
        $user = $this->users->findById($token->userId);
        if ($user === null || !$user->isActive()) {
            return false;
        }

        $completed = $this->tokens->consumeAtomically($token, $now, function () use ($user, $plainPassword, $purpose): void {
            if ($purpose === AccountLinkPurpose::PasswordReset || $user->requiresPasswordSetup()) {
                $user->setPassword($this->hasher->hashPassword($user, $plainPassword));
            }
            if ($purpose === AccountLinkPurpose::Invitation) {
                $this->memberships->acceptInvitationsForActor($user->id());
            }
            if ($purpose === AccountLinkPurpose::PasswordReset || $purpose === AccountLinkPurpose::Invitation) {
                $user->markEmailVerified();
                $this->users->save($user);
            }
        });
        if ($completed && $purpose === AccountLinkPurpose::PasswordReset) $this->refreshSessions->revokeUser($user->id());
        return $completed;
    }
}
