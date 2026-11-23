<?php

declare(strict_types=1);

namespace App\Service\Auth;

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

    /** @return array{userId:string} */
    public function execute(string $email, string $plainPassword): array
    {
        $email = mb_strtolower(trim($email));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 190
            || strlen($plainPassword) < 12 || strlen($plainPassword) > 4096) {
            throw new \InvalidArgumentException('Email or password is invalid.');
        }

        if ($this->users->findByEmail($email) !== null) {
            throw new OperatorRegistrationConflict('The email is already in use.');
        }

        $user = User::create(bin2hex(random_bytes(16)), $email, 'pending');
        $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));

        try {
            $this->entities->wrapInTransaction(function (EntityManagerInterface $entities) use ($user): void {
                $entities->persist($user);
            });
        } catch (UniqueConstraintViolationException $exception) {
            throw new OperatorRegistrationConflict('The email is already in use.', previous: $exception);
        }

        return ['userId' => $user->id()];
    }
}
