<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AccountActionToken;
use App\Entity\AccountLinkPurpose;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(AccountActionTokenRepository::class)]
final readonly class DoctrineAccountActionTokenRepository implements AccountActionTokenRepository
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function invalidateOutstanding(string $userId, AccountLinkPurpose $purpose, \DateTimeImmutable $now): void
    {
        $this->entityManager->createQueryBuilder()->update(AccountActionToken::class, 'token')
            ->set('token.consumedAt', ':now')->where('token.userId = :userId')
            ->andWhere('token.purpose = :purpose')->andWhere('token.consumedAt IS NULL')
            ->setParameter('now', $now)->setParameter('userId', $userId)->setParameter('purpose', $purpose)
            ->getQuery()->execute();
    }

    public function save(AccountActionToken $token): void
    {
        $this->entityManager->persist($token);
        $this->entityManager->flush();
    }

    public function findUsable(string $tokenHash, AccountLinkPurpose $purpose, \DateTimeImmutable $now): ?AccountActionToken
    {
        return $this->entityManager->createQueryBuilder()->select('token')->from(AccountActionToken::class, 'token')
            ->where('token.tokenHash = :hash')->andWhere('token.purpose = :purpose')
            ->andWhere('token.consumedAt IS NULL')->andWhere('token.expiresAt > :now')
            ->setParameter('hash', $tokenHash)->setParameter('purpose', $purpose)->setParameter('now', $now)
            ->getQuery()->getOneOrNullResult();
    }

    public function consumeAtomically(AccountActionToken $token, \DateTimeImmutable $now, callable $operation): bool
    {
        return $this->entityManager->wrapInTransaction(function () use ($token, $now, $operation): bool {
            $managed = $this->entityManager->find(AccountActionToken::class, $token->id(), LockMode::PESSIMISTIC_WRITE);
            if (!$managed instanceof AccountActionToken || !$managed->isUsable($now)) {
                return false;
            }
            $managed->consume($now);
            $operation();
            $this->entityManager->flush();
            return true;
        });
    }
}
