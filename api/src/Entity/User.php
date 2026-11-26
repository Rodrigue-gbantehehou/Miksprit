<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\EquatableInterface;

#[ORM\Entity]
#[ORM\Table(name: 'users')]
#[ORM\UniqueConstraint(name: 'uniq_user_email', columns: ['email'])]
class User implements UserInterface, PasswordAuthenticatedUserInterface, EquatableInterface
{
    /** @param list<string> $roles */
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 36)]
        private readonly string $id,
        #[ORM\Column(type: 'string', length: 190)]
        private string $email,
        #[ORM\Column(name: 'password_hash', type: 'string', length: 255)]
        private string $password,
        #[ORM\Column(type: 'json')]
        private array $roles = ['ROLE_USER'],
        #[ORM\Column(type: 'boolean')]
        private bool $active = true,
        #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
        public readonly \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
        #[ORM\Column(name: 'password_setup_required', type: 'boolean', options: ['default' => false])]
        private bool $passwordSetupRequired = false,
        #[ORM\Column(name: 'email_verified_at', type: 'datetime_immutable', nullable: true)]
        private ?\DateTimeImmutable $emailVerifiedAt = null,
    ) {
        $this->email = self::normalizeEmail($email);
        if ($id === '' || strlen($id) > 36) {
            throw new \InvalidArgumentException('User ID is invalid.');
        }
    }

    public static function create(string $id, string $email, string $hashedPassword, bool $superAdmin = false): self
    {
        return new self($id, $email, $hashedPassword, $superAdmin ? ['ROLE_USER', 'ROLE_SUPER_ADMIN'] : ['ROLE_USER']);
    }

    public static function invited(string $id, string $email, string $hashedPassword): self
    {
        return new self($id, $email, $hashedPassword, ['ROLE_USER'], true, new \DateTimeImmutable(), true);
    }

    public function getUserIdentifier(): string { return $this->email; }
    public function getPassword(): ?string { return $this->password; }
    public function setPassword(string $password): void { $this->password = $password; $this->passwordSetupRequired = false; }
    public function setInvitationPasswordHash(string $passwordHash): void
    {
        if (!$this->passwordSetupRequired) throw new \LogicException('Invitation password setup is not pending.');
        $this->password = $passwordHash;
    }
    public function requiresPasswordSetup(): bool { return $this->passwordSetupRequired; }
    public function emailVerifiedAt(): ?\DateTimeImmutable { return $this->emailVerifiedAt; }
    public function markEmailVerified(?\DateTimeImmutable $at = null): void { $this->emailVerifiedAt ??= $at ?? new \DateTimeImmutable(); }
    public function getRoles(): array { return array_values(array_unique($this->roles)); }
    public function eraseCredentials(): void {}
    public function id(): string { return $this->id; }
    public function email(): string { return $this->email; }
    public function isActive(): bool { return $this->active; }
    public function isSuperAdmin(): bool { return in_array('ROLE_SUPER_ADMIN', $this->roles, true); }
    public function setActive(bool $active): void { $this->active = $active; }

    public function isEqualTo(UserInterface $user): bool
    {
        return $user instanceof self
            && hash_equals($this->id, $user->id)
            && hash_equals($this->email, $user->email)
            && hash_equals($this->password, $user->password)
            && $this->active === $user->active
            && $this->getRoles() === $user->getRoles();
    }

    private static function normalizeEmail(string $email): string
    {
        $email = mb_strtolower(trim($email));
        if (strlen($email) > 190 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('A valid email address of at most 190 bytes is required.');
        }
        return $email;
    }
}
