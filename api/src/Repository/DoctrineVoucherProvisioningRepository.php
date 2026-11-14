<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\OrderState;
use App\Entity\Organization;
use App\Entity\OrganizationStatus;
use App\Entity\Router;
use App\Entity\Site;
use App\Entity\TenantContext;
use App\Entity\Voucher;
use App\Entity\VoucherStatus;
use App\Entity\WifiOrder;
use App\Service\Router\VoucherRevocationRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(VoucherProvisioningRepository::class)]
final readonly class DoctrineVoucherProvisioningRepository implements VoucherRevocationRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function findRouterForTenant(TenantContext $context, string $routerId): ?Router
    {
        if (trim($routerId) === '' || strlen($routerId) > 80) {
            return null;
        }

        return $this->entityManager->createQueryBuilder()
            ->select('router')
            ->from(Router::class, 'router')
            ->where('router.id = :routerId')
            ->andWhere('router.ownerTenantId.value = :tenantId')
            ->setParameter('routerId', $routerId)
            ->setParameter('tenantId', $context->tenantId->value)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findSiteForTenant(TenantContext $context, string $siteId): ?Site
    {
        if (trim($siteId) === '' || strlen($siteId) > 80) {
            return null;
        }

        return $this->entityManager->createQueryBuilder()
            ->select('site')
            ->from(Site::class, 'site')
            ->where('site.id = :siteId')
            ->andWhere('site.ownerTenantId.value = :tenantId')
            ->setParameter('siteId', $siteId)
            ->setParameter('tenantId', $context->tenantId->value)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findVoucherForTenant(TenantContext $context, string $voucherId): ?Voucher
    {
        if (trim($voucherId) === '' || strlen($voucherId) > 80) {
            return null;
        }

        return $this->entityManager->createQueryBuilder()
            ->select('voucher')
            ->from(Voucher::class, 'voucher')
            ->where('voucher.id = :voucherId')
            ->andWhere('voucher.ownerTenantId.value = :tenantId')
            ->setParameter('voucherId', $voucherId)
            ->setParameter('tenantId', $context->tenantId->value)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOrderForTenantByPublicReference(TenantContext $context, string $publicReference): ?WifiOrder
    {
        if (trim($publicReference) === '' || strlen($publicReference) > 160) {
            return null;
        }

        return $this->entityManager->createQueryBuilder()
            ->select('wifiOrder')
            ->from(WifiOrder::class, 'wifiOrder')
            ->where('wifiOrder.publicReference = :publicReference')
            ->andWhere('wifiOrder.ownerTenantId.value = :tenantId')
            ->setParameter('publicReference', $publicReference)
            ->setParameter('tenantId', $context->tenantId->value)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function encryptedCredentialForTenant(TenantContext $context, string $voucherId): ?string
    {
        $value = $this->entityManager->createQueryBuilder()
            ->select('voucher.encryptedCredential')
            ->from(Voucher::class, 'voucher')
            ->where('voucher.id = :voucherId')
            ->andWhere('voucher.ownerTenantId.value = :tenantId')
            ->setParameter('voucherId', $voucherId)
            ->setParameter('tenantId', $context->tenantId->value)
            ->getQuery()
            ->getOneOrNullResult(\Doctrine\ORM\Query::HYDRATE_SCALAR_COLUMN);

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function completeProvisioning(Voucher $voucher, WifiOrder $order): void
    {
        if (!$this->entityManager->contains($voucher) || !$this->entityManager->contains($order)) {
            throw new \LogicException('Provisioning completion requires tenant-scoped managed entities.');
        }
        if ($voucher->status() !== VoucherStatus::Active || $order->state() !== OrderState::Fulfilled
            || $order->voucherReference() !== $voucher->id || $voucher->orderReference !== $order->publicReference
            || !$voucher->tenantId()->equals($order->tenantId()) || $voucher->siteId !== $order->plan->siteId) {
            throw new \DomainException('Voucher and order completion do not match.');
        }
        $expiresAt = $voucher->expiresAt();
        if ($expiresAt === null || $expiresAt <= new \DateTimeImmutable()) {
            throw new \DomainException('A provisioned voucher must have a future expiration.');
        }

        $connection = $this->entityManager->getConnection();
        $connection->transactional(function (Connection $transaction) use ($voucher, $order): void {
            $platform = $transaction->getDatabasePlatform();
            $lockSuffix = ($platform instanceof AbstractMySQLPlatform || $platform instanceof PostgreSQLPlatform)
                ? ' FOR UPDATE'
                : '';

            $voucherRow = $transaction->fetchAssociative(
                'SELECT tenant_id, site_id, order_reference, status, expires_at FROM vouchers WHERE id = ?'.$lockSuffix,
                [$voucher->id],
            );
            $orderRow = $transaction->fetchAssociative(
                'SELECT tenant_id, public_reference, state, voucher_reference, plan_site_id FROM wifi_orders WHERE id = ?'.$lockSuffix,
                [$order->id],
            );

            if ($voucherRow === false || $orderRow === false) {
                throw new \DomainException('Provisioning records no longer exist.');
            }

            $sameOwner = $voucherRow['tenant_id'] === $orderRow['tenant_id']
                && $voucherRow['tenant_id'] === $voucher->tenantId()->value;
            $sameOrder = $voucherRow['order_reference'] === $orderRow['public_reference']
                && $voucherRow['order_reference'] === $order->publicReference;
            $sameSite = $voucherRow['site_id'] === $orderRow['plan_site_id']
                && $voucherRow['site_id'] === $voucher->siteId;

            // Make completion retry-safe after a commit whose acknowledgment was lost.
            if ($sameOwner && $sameOrder && $sameSite
                && $voucherRow['status'] === VoucherStatus::Active->value
                && $orderRow['state'] === OrderState::Fulfilled->value
                && $orderRow['voucher_reference'] === $voucher->id) {
                return;
            }

            if (!$sameOwner || !$sameOrder || !$sameSite
                || $voucherRow['status'] !== VoucherStatus::Reserved->value
                || $orderRow['state'] !== OrderState::AccessPending->value) {
                throw new \DomainException('Persisted voucher and order are no longer eligible for completion.');
            }

            $this->entityManager->flush();
        });
    }

    public function completeRevocation(Voucher $voucher): void
    {
        if (!$this->entityManager->contains($voucher) || $voucher->status() !== VoucherStatus::Revoked) {
            throw new \LogicException('Revocation completion requires a managed, revoked tenant-scoped voucher.');
        }
        $this->entityManager->flush();
    }
}
