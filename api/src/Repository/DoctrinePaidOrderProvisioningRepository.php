<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\OrderState;
use App\Entity\PaymentOutboxMessage;
use App\Entity\PaymentOutboxMessageState;
use App\Entity\Router;
use App\Entity\Voucher;
use App\Entity\WifiOrder;
use App\Service\Payment\PaidOrderProvisioningContext;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(PaidOrderProvisioningRepository::class)]
final readonly class DoctrinePaidOrderProvisioningRepository implements PaidOrderProvisioningRepository
{
    public function __construct(private Connection $connection, private EntityManagerInterface $entities)
    {
    }

    public function claimNext(): ?PaymentOutboxMessage
    {
        $id = $this->connection->transactional(function (Connection $connection): ?string {
            $suffix = $connection->getDatabasePlatform() instanceof AbstractMySQLPlatform || $connection->getDatabasePlatform() instanceof PostgreSQLPlatform
                ? ' FOR UPDATE' : '';
            $id = $connection->fetchOne("SELECT id FROM payment_outbox WHERE state = 'pending' ORDER BY created_at, id LIMIT 1".$suffix);
            if (!is_string($id)) return null;
            $connection->executeStatement("UPDATE payment_outbox SET state = 'processing' WHERE id = ? AND state = 'pending'", [$id]);
            return $id;
        });
        if ($id === null) return null;
        $message = $this->entities->find(PaymentOutboxMessage::class, $id);
        if (!$message instanceof PaymentOutboxMessage || $message->state() !== PaymentOutboxMessageState::Processing) return null;
        return $message;
    }

    public function findContext(PaymentOutboxMessage $message): ?PaidOrderProvisioningContext
    {
        $order = $this->entities->createQueryBuilder()->select('wifiOrder')->from(WifiOrder::class, 'wifiOrder')
            ->where('wifiOrder.publicReference = :reference')->andWhere('wifiOrder.ownerTenantId.value = :tenant')
            ->setParameter('reference', $message->orderReference)->setParameter('tenant', $message->tenantId->value)
            ->getQuery()->getOneOrNullResult();
        if (!$order instanceof WifiOrder || $order->state() !== OrderState::AccessPending) return null;
        $routerIds = $this->connection->fetchFirstColumn(
            "SELECT r.id FROM routers r
             INNER JOIN sites s ON s.id = r.site_id AND s.tenant_id = r.tenant_id AND s.status = 'active'
             INNER JOIN organizations o ON o.tenant_id = r.tenant_id AND o.organization_status = 'active'
             WHERE r.site_id = ? AND r.tenant_id = ? AND r.status = ? ORDER BY r.created_at, r.id LIMIT 2",
            [$order->plan->siteId, $message->tenantId->value, \App\Entity\RouterStatus::Active->value],
        );
        if (count($routerIds) !== 1) return null;
        $routerId = $routerIds[0];
        $router = is_string($routerId) ? $this->entities->find(Router::class, $routerId) : null;
        if (!$router instanceof Router) return null;
        return new PaidOrderProvisioningContext($order, $router);
    }

    public function findVoucher(PaymentOutboxMessage $message): ?Voucher
    {
        $voucher = $this->entities->createQueryBuilder()->select('voucher')->from(Voucher::class, 'voucher')
            ->where('voucher.orderReference = :reference')->andWhere('voucher.ownerTenantId.value = :tenant')
            ->setParameter('reference', $message->orderReference)->setParameter('tenant', $message->tenantId->value)
            ->getQuery()->getOneOrNullResult();
        return $voucher instanceof Voucher ? $voucher : null;
    }

    public function saveVoucher(Voucher $voucher): void
    {
        if (!$this->entities->contains($voucher)) $this->entities->persist($voucher);
        $this->entities->flush();
    }

    public function complete(PaymentOutboxMessage $message, Voucher $voucher, WifiOrder $order): void
    {
        if (!$this->entities->contains($message) || !$this->entities->contains($voucher) || !$this->entities->contains($order)
            || $message->state() !== PaymentOutboxMessageState::Processing || $voucher->status()->value !== 'active'
            || $order->state() !== OrderState::Fulfilled || $order->voucherReference() !== $voucher->id) {
            throw new \DomainException('Paid order provisioning state is inconsistent.');
        }
        $message->markCompleted();
        $this->connection->transactional(fn () => $this->entities->flush());
    }

    public function fail(PaymentOutboxMessage $message): void
    {
        $message->markFailed();
        $order = $this->entities->createQueryBuilder()->select('wifiOrder')->from(WifiOrder::class, 'wifiOrder')
            ->where('wifiOrder.publicReference = :reference')->andWhere('wifiOrder.ownerTenantId.value = :tenant')
            ->setParameter('reference', $message->orderReference)->setParameter('tenant', $message->tenantId->value)
            ->getQuery()->getOneOrNullResult();
        if ($order instanceof WifiOrder && $order->state() === OrderState::AccessPending) $order->markProvisioningFailed();
        $this->entities->flush();
    }

    public function release(PaymentOutboxMessage $message): void
    {
        $message->releaseForRetry();
        $this->entities->flush();
    }
}
