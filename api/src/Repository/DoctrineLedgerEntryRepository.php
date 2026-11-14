<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\LedgerEntry;
use App\Entity\TenantContext;
use App\Service\Ledger\LedgerEntryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(LedgerEntryRepository::class)]
final readonly class DoctrineLedgerEntryRepository implements LedgerEntryRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function findByIdempotencyKey(TenantContext $tenant, string $idempotencyKey): ?LedgerEntry
    {
        return $this->entityManager->createQueryBuilder()
            ->select('entry')
            ->from(LedgerEntry::class, 'entry')
            ->where('entry.ownerTenantId.value = :tenantId')
            ->andWhere('entry.idempotencyKey = :idempotencyKey')
            ->setParameter('tenantId', $tenant->tenantId->value)
            ->setParameter('idempotencyKey', $idempotencyKey)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function append(TenantContext $tenant, LedgerEntry $entry): void
    {
        if (!$entry->tenantId()->equals($tenant->tenantId)) {
            throw new \DomainException('Ledger entry does not belong to the current tenant.');
        }
        $this->entityManager->persist($entry);
        $this->entityManager->flush();
    }
}
