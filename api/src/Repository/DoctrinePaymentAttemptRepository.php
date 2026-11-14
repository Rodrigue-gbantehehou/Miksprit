<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PaymentAttempt;
use App\Entity\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/** Doctrine adapter; every attempt lookup includes both primary ID and authorized tenant ID. */
#[AsAlias(PaymentAttemptRepository::class)]
final readonly class DoctrinePaymentAttemptRepository implements PaymentAttemptRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function findForTenant(TenantContext $context, string $attemptId): ?PaymentAttempt
    {
        if (trim($attemptId) === '' || strlen($attemptId) > 80) {
            return null;
        }

        return $this->entityManager->createQueryBuilder()
            ->select('attempt')
            ->from(PaymentAttempt::class, 'attempt')
            ->where('attempt.id = :attemptId')
            ->andWhere('attempt.tenantId.value = :tenantId')
            ->setParameter('attemptId', $attemptId)
            ->setParameter('tenantId', $context->tenantId->value)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function save(TenantContext $context, PaymentAttempt $attempt): void
    {
        if (!$attempt->tenantId->equals($context->tenantId)) {
            throw new \DomainException('Payment attempt does not belong to the current tenant.');
        }
        if (!$this->entityManager->contains($attempt)) {
            $existing = $this->entityManager->find(PaymentAttempt::class, $attempt->id);
            if ($existing !== null) {
                throw new \LogicException('Payment attempts must be updated through a tenant-scoped managed instance.');
            }

            $this->entityManager->persist($attempt);
        }

        $this->entityManager->flush();
    }
}
