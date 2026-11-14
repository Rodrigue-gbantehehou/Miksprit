<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PendingOperatorRegistration;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\LockMode;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(PendingOperatorRegistrationRepository::class)]
final readonly class DoctrinePendingOperatorRegistrationRepository implements PendingOperatorRegistrationRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function invalidateOutstandingForEmail(string $email, \DateTimeImmutable $now): void
    {
        $this->entityManager->createQueryBuilder()->update(PendingOperatorRegistration::class, 'registration')
            ->set('registration.consumedAt', ':now')
            ->where('registration.email = :email')
            ->andWhere('registration.consumedAt IS NULL')
            ->setParameter('now', $now)
            ->setParameter('email', $email)
            ->getQuery()->execute();
    }

    public function save(PendingOperatorRegistration $registration): void
    {
        $this->entityManager->persist($registration);
        $this->entityManager->flush();
    }

    public function findUsable(string $tokenHash, \DateTimeImmutable $now): ?PendingOperatorRegistration
    {
        return $this->entityManager->createQueryBuilder()->select('registration')
            ->from(PendingOperatorRegistration::class, 'registration')
            ->where('registration.tokenHash = :hash')
            ->andWhere('registration.consumedAt IS NULL')
            ->andWhere('registration.expiresAt > :now')
            ->setParameter('hash', $tokenHash)
            ->setParameter('now', $now)
            ->getQuery()->getOneOrNullResult();
    }

    public function consumeAtomically(PendingOperatorRegistration $registration, \DateTimeImmutable $now, callable $operation): bool
    {
        return $this->entityManager->wrapInTransaction(function () use ($registration, $now, $operation): bool {
            $managed = $this->entityManager->find(PendingOperatorRegistration::class, $registration->id, LockMode::PESSIMISTIC_WRITE);
            if (!$managed instanceof PendingOperatorRegistration || !$managed->isUsable($now)) {
                return false;
            }
            $managed->consume($now);
            $operation($managed);
            $this->entityManager->flush();
            return true;
        });
    }
}
