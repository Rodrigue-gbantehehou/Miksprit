<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Router;
use App\Entity\Voucher;
use App\Entity\VoucherStatus;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(ExpiredVoucherRepository::class)]
final readonly class DoctrineExpiredVoucherRepository implements ExpiredVoucherRepository
{
    public function __construct(private Connection $connection, private EntityManagerInterface $entities)
    {
    }

    public function due(int $limit): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT v.id AS voucher_id, r.id AS router_id FROM vouchers v
             INNER JOIN routers r ON r.tenant_id = v.tenant_id AND r.id = v.router_id
             WHERE v.status = :voucherStatus AND v.expires_at <= :now ORDER BY v.expires_at, v.id LIMIT ".$limit,
            ['voucherStatus' => VoucherStatus::Active->value, 'now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s')],
        );
        $due = [];
        foreach ($rows as $row) {
            $voucher = $this->entities->find(Voucher::class, (string) $row['voucher_id']);
            $router = $this->entities->find(Router::class, (string) $row['router_id']);
            if ($voucher instanceof Voucher && $router instanceof Router) $due[] = ['voucher' => $voucher, 'router' => $router];
        }
        return $due;
    }

    public function unassignedDueCount(): int
    {
        return (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM vouchers v LEFT JOIN routers r ON r.tenant_id = v.tenant_id AND r.id = v.router_id
             WHERE v.status = 'active' AND v.expires_at <= ? AND r.id IS NULL",
            [(new \DateTimeImmutable())->format('Y-m-d H:i:s')],
        );
    }

    public function complete(Voucher $voucher): void
    {
        if (!$this->entities->contains($voucher) || $voucher->status() !== VoucherStatus::Expired) {
            throw new \DomainException('Only a managed, expired voucher can be persisted.');
        }
        $this->entities->flush();
    }
}
