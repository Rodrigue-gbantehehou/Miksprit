<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Entity\Membership;
use App\Entity\Organization;
use App\Entity\TenantId;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** Creates an active login, organization and owner membership in one transaction. */
final readonly class CreateOperatorAccount
{
    public function __construct(
        private UserRepository $users,
        private EntityManagerInterface $entities,
        private UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    /** @return array{userId:string,tenantId:string} */
    public function execute(string $email, string $organizationName, string $organizationSlug, string $plainPassword): array
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

        if ($this->users->findByEmail($email) !== null
            || $this->entities->getRepository(Organization::class)->findOneBy(['slug' => $organizationSlug]) !== null) {
            throw new OperatorRegistrationConflict('The email or organization slug is already in use.');
        }

        $user = User::create(bin2hex(random_bytes(16)), $email, 'pending');
        $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));
        $tenantId = new TenantId(bin2hex(random_bytes(16)));
        $organization = Organization::create($tenantId, $organizationName, $organizationSlug);
        $membership = Membership::owner(bin2hex(random_bytes(16)), $tenantId, $user->id());

        try {
            $this->entities->wrapInTransaction(function (EntityManagerInterface $entities) use ($user, $organization, $membership): void {
                $entities->persist($user);
                $entities->persist($organization);
                $entities->persist($membership);
            });
        } catch (UniqueConstraintViolationException $exception) {
            throw new OperatorRegistrationConflict('The email or organization slug is already in use.', previous: $exception);
        }

        return ['userId' => $user->id(), 'tenantId' => $tenantId->value];
    }
}
