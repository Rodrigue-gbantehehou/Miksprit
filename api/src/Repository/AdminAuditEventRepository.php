<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AdminAuditEvent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(AdminAuditEventStore::class)]
final readonly class AdminAuditEventRepository implements AdminAuditEventStore
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function append(AdminAuditEvent $event): void
    {
        $this->entityManager->persist($event);
        $this->entityManager->flush();
    }

    /** @return list<AdminAuditEvent> */
    public function latest(int $limit = 100): array
    {
        return $this->entityManager->getRepository(AdminAuditEvent::class)->findBy([], ['createdAt' => 'DESC'], min(200, max(1, $limit)));
    }
}
