<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'pending_operator_registrations')]
#[ORM\UniqueConstraint(name: 'uniq_pending_operator_registration_token', columns: ['token_hash'])]
#[ORM\Index(name: 'idx_pending_operator_registration_expiry', columns: ['expires_at'])]
final class PendingOperatorRegistration
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 36)]
        public readonly string $id,
        #[ORM\Column(type: 'string', length: 190)]
        public readonly string $email,
        #[ORM\Column(name: 'organization_name', type: 'string', length: 160)]
        public readonly string $organizationName,
        #[ORM\Column(name: 'organization_slug', type: 'string', length: 80)]
        public readonly string $organizationSlug,
        #[ORM\Column(name: 'password_hash', type: 'string', length: 255)]
        public readonly string $passwordHash,
        #[ORM\Column(name: 'token_hash', type: 'string', length: 64)]
        public readonly string $tokenHash,
        #[ORM\Column(name: 'expires_at', type: 'datetime_immutable')]
        public readonly \DateTimeImmutable $expiresAt,
        #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
        public readonly \DateTimeImmutable $createdAt,
        #[ORM\Column(name: 'consumed_at', type: 'datetime_immutable', nullable: true)]
        private ?\DateTimeImmutable $consumedAt = null,
    ) {
        if (preg_match('/^[a-f0-9]{32}$/D', $id) !== 1
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false
            || preg_match('/^[a-f0-9]{64}$/D', $tokenHash) !== 1
            || $passwordHash === '') {
            throw new \InvalidArgumentException('Invalid pending operator registration.');
        }
    }

    public function isUsable(\DateTimeImmutable $now): bool
    {
        return $this->consumedAt === null && $this->expiresAt > $now;
    }

    public function consume(\DateTimeImmutable $now): void
    {
        if (!$this->isUsable($now)) {
            throw new \DomainException('Registration link is expired or already used.');
        }
        $this->consumedAt = $now;
    }
}
