<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'account_action_tokens')]
#[ORM\UniqueConstraint(name: 'uniq_account_action_token_hash', columns: ['token_hash'])]
#[ORM\Index(name: 'idx_account_action_user_purpose', columns: ['user_id', 'purpose'])]
final class AccountActionToken
{
    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 36)]
        private string $id,
        #[ORM\Column(name: 'user_id', type: 'string', length: 36)]
        private string $userId,
        #[ORM\Column(name: 'token_hash', type: 'string', length: 64)]
        private string $tokenHash,
        #[ORM\Column(enumType: AccountLinkPurpose::class, length: 24)]
        private AccountLinkPurpose $purpose,
        #[ORM\Column(name: 'expires_at', type: 'datetime_immutable')]
        private \DateTimeImmutable $expiresAt,
        #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
        private \DateTimeImmutable $createdAt,
        #[ORM\Column(name: 'consumed_at', type: 'datetime_immutable', nullable: true)]
        private ?\DateTimeImmutable $consumedAt = null,
    ) {
        if (preg_match('/^(?:[a-f0-9]{32}|[a-f0-9-]{36})$/Di', $id) !== 1
            || preg_match('/^(?:[a-f0-9]{32}|[a-f0-9-]{36})$/Di', $userId) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $tokenHash) !== 1) {
            throw new \InvalidArgumentException('Invalid account action token data.');
        }
    }

    public static function issue(string $id, string $userId, string $tokenHash, AccountLinkPurpose $purpose, \DateTimeImmutable $expiresAt): self
    {
        return new self($id, $userId, $tokenHash, $purpose, $expiresAt, new \DateTimeImmutable());
    }

    public function isUsable(\DateTimeImmutable $now): bool
    {
        return $this->consumedAt === null && $this->expiresAt > $now;
    }

    public function id(): string { return $this->id; }

    public function userId(): string { return $this->userId; }

    public function consume(\DateTimeImmutable $now): void
    {
        if (!$this->isUsable($now)) {
            throw new \DomainException('Account action link is expired or already used.');
        }
        $this->consumedAt = $now;
    }
}
