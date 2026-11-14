<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Entity\Organization;
use App\Entity\PendingOperatorRegistration;
use App\Entity\User;
use App\Repository\PendingOperatorRegistrationRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final readonly class RequestOperatorRegistration
{
    public function __construct(
        private PendingOperatorRegistrationRepository $registrations,
        private UserRepository $users,
        private EntityManagerInterface $entities,
        private UserPasswordHasherInterface $passwordHasher,
        private OperatorRegistrationVerificationEmail $email,
    ) {
    }

    /** Returns false for an existing email or slug; callers must use the same public response either way. */
    public function request(string $email, string $organizationName, string $organizationSlug, string $plainPassword): bool
    {
        $email = mb_strtolower(trim($email));
        $organizationName = trim($organizationName);
        $organizationSlug = strtolower(trim($organizationSlug));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 190
            || $organizationName === '' || mb_strlen($organizationName) > 160
            || preg_match('/^[a-z0-9](?:[a-z0-9-]{0,78}[a-z0-9])?$/D', $organizationSlug) !== 1
            || strlen($plainPassword) < 12 || strlen($plainPassword) > 4096) {
            throw new \InvalidArgumentException('Email, organization name, slug, or password is invalid.');
        }

        $organizationExists = $this->entities->getRepository(Organization::class)->findOneBy(['slug' => $organizationSlug]) !== null;
        if ($this->users->findByEmail($email) !== null || $organizationExists) {
            return false;
        }

        $userForHash = User::create(bin2hex(random_bytes(16)), $email, 'pending');
        $passwordHash = $this->passwordHasher->hashPassword($userForHash, $plainPassword);
        $now = new \DateTimeImmutable();
        $rawToken = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $this->registrations->invalidateOutstandingForEmail($email, $now);
        $this->registrations->save(new PendingOperatorRegistration(
            bin2hex(random_bytes(16)),
            $email,
            $organizationName,
            $organizationSlug,
            $passwordHash,
            hash('sha256', $rawToken),
            $now->add(new \DateInterval('PT1H')),
            $now,
        ));
        $this->email->send($email, $rawToken);
        return true;
    }
}
