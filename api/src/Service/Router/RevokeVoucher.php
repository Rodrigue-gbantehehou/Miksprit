<?php

declare(strict_types=1);

namespace App\Service\Router;

use App\Entity\OrderState;
use App\Entity\RouterStatus;
use App\Entity\TenantAccess;
use App\Entity\TenantContext;
use App\Entity\TenantPermission;
use App\Entity\VoucherStatus;

/** Revoke from RouterOS first; only then persist the voucher's terminal state. */
final readonly class RevokeVoucher
{
    public function __construct(
        private VoucherRevocationRepository $repository,
        private RouterManagementFactory $routers,
        private VoucherCredentialCipher $cipher,
    ) {
    }

    public function execute(TenantContext $context, string $routerId, string $voucherId): bool
    {
        if (!$context->allows(TenantPermission::ManageVouchers)) {
            throw new \DomainException('Voucher revocation is not permitted.');
        }
        $router = $this->repository->findRouterForTenant($context, $routerId);
        $voucher = $this->repository->findVoucherForTenant($context, $voucherId);
        if ($router === null || $voucher === null) {
            throw new \DomainException('Voucher or router not found.');
        }
        TenantAccess::assertOwns($context, $router);
        TenantAccess::assertOwns($context, $voucher);
        if ($router->status() !== RouterStatus::Active || $router->siteId !== $voucher->siteId
            || $voucher->status() !== VoucherStatus::Active || $voucher->orderReference === null) {
            throw new \DomainException('Voucher is not eligible for router revocation.');
        }
        $order = $this->repository->findOrderForTenantByPublicReference($context, $voucher->orderReference);
        if ($order === null || $order->state() !== OrderState::Fulfilled || $order->voucherReference() !== $voucher->id
            || $order->plan->siteId !== $voucher->siteId) {
            throw new \DomainException('Voucher order requires reconciliation before revocation.');
        }
        TenantAccess::assertOwns($context, $order);
        $envelope = $this->repository->encryptedCredentialForTenant($context, $voucher->id);
        if ($envelope === null) {
            throw new \DomainException('Voucher credential is unavailable for safe revocation.');
        }
        $credential = $this->cipher->decrypt($context->tenantId, $voucher->id, $envelope);
        try {
            // false means RouterOS no longer has this exact username; it is safe to finalize revoke.
            $this->routers->forRouterManagement($router)->revokeUser($credential['username']);
        } catch (\Throwable) {
            throw new \RuntimeException('RouterOS voucher revocation failed or has an unknown outcome; reconcile before retry.');
        } finally {
            if (function_exists('sodium_memzero')) {
                sodium_memzero($credential['username']);
                sodium_memzero($credential['password']);
            }
        }
        $voucher->revoke();
        $this->repository->completeRevocation($voucher);

        return true;
    }
}
