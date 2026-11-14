<?php

declare(strict_types=1);

namespace App\Service\Router;

use App\Entity\RouterStatus;
use App\Entity\SiteStatus;
use App\Entity\OrderState;
use App\Entity\TenantAccess;
use App\Entity\TenantContext;
use App\Entity\TenantPermission;
use App\Entity\VoucherStatus;
use App\Entity\WifiOrder;
use App\Service\Provider\Router\RouterOsCommandRejected;
use App\Service\Provider\Router\RouterOsUnavailable;

/** Coordinates tenant-scoped voucher activation with an idempotent, fail-closed RouterOS port. */
final readonly class ProvisionVoucher
{
    public function __construct(
        private VoucherProvisioningRepository $repository,
        private HotspotProvisionerFactory $provisioners,
        private VoucherCredentialCipher $cipher,
        private VoucherProvisioningIdempotencyStore $idempotency,
    ) {
    }

    public function execute(TenantContext $context, VoucherProvisioningRequest $request): VoucherProvisioningResult
    {
        if (!$context->allows(TenantPermission::ManageVouchers)) {
            throw new \DomainException('Voucher provisioning is not permitted.');
        }

        $router = $this->repository->findRouterForTenant($context, $request->routerId);
        $voucher = $this->repository->findVoucherForTenant($context, $request->voucherId);
        if ($router === null || $voucher === null) {
            throw new \DomainException('Voucher or router not found.');
        }
        TenantAccess::assertOwns($context, $router);
        TenantAccess::assertOwns($context, $voucher);

        $site = $this->repository->findSiteForTenant($context, $voucher->siteId);
        if ($site === null) {
            throw new \DomainException('Voucher site not found.');
        }
        TenantAccess::assertOwns($context, $site);
        if ($site->status() !== SiteStatus::Active) {
            throw new \DomainException('Site is not active for voucher provisioning.');
        }
        if ($site->id !== $voucher->siteId || $site->id !== $router->siteId) {
            throw new \DomainException('Router or voucher does not belong to the active site.');
        }

        // Free/admin vouchers are not modeled yet. Never provision an unlinked voucher.
        if ($voucher->orderReference === null) {
            throw new \DomainException('Voucher must be linked to a paid order before provisioning.');
        }
        $order = $this->repository->findOrderForTenantByPublicReference($context, $voucher->orderReference);
        if ($order === null || $order->publicReference !== $voucher->orderReference) {
            throw new \DomainException('Voucher order not found.');
        }
        TenantAccess::assertOwns($context, $order);
        if ($order->plan->siteId !== $site->id || $order->plan->siteId !== $voucher->siteId) {
            throw new \DomainException('Voucher order does not match its site.');
        }

        if ($router->status() !== RouterStatus::Active || $router->siteId !== $voucher->siteId) {
            throw new \DomainException('Router is unavailable for this voucher.');
        }
        if ($voucher->status() === VoucherStatus::Active) {
            if ($order->state() !== OrderState::Fulfilled || $order->voucherReference() !== $voucher->id) {
                throw new \RuntimeException('Voucher and order states require reconciliation.');
            }
            return new VoucherProvisioningResult($voucher->id, $voucher->status());
        }
        if ($voucher->status() !== VoucherStatus::Reserved || $request->expiresAt <= new \DateTimeImmutable()) {
            throw new \DomainException('Voucher is not eligible for provisioning.');
        }
        if ($order->state() !== OrderState::AccessPending) {
            throw new \DomainException('Only an order with verified payment and access pending can provision a voucher.');
        }

        $key = 'voucher-provisioning:'.$context->tenantId->value.':'.$voucher->id;
        if (!$this->idempotency->claim($key)) {
            // An active persisted voucher is the only safe proof that a previous attempt completed.
            $freshVoucher = $this->repository->findVoucherForTenant($context, $voucher->id);
            if ($freshVoucher?->status() === VoucherStatus::Active) {
                return new VoucherProvisioningResult($freshVoucher->id, $freshVoucher->status());
            }
            throw new \RuntimeException('Voucher provisioning is already in progress; retry after reconciliation.');
        }

        try {
            $port = $this->provisioners->forRouter($router);
            $port->createUser($request->username, $request->passwordForProvisioning(), $request->profile, $request->limitUptime, $request->limitBytes, $request->sharedUsers);
        } catch (RouterOsCommandRejected|HotspotProvisioningUnavailable|RouterOsUnavailable) {
            try {
                $this->idempotency->release($key);
            } catch (\Throwable) {
                throw new \RuntimeException('Router rejected voucher provisioning, but retry state could not be updated.');
            }
            throw new \RuntimeException('RouterOS confirmed that voucher provisioning did not complete.');
        } catch (\Throwable) {
            // A lost connection can happen after RouterOS applied the command. Keep the key claimed
            // and require reconciliation before any retry to avoid duplicate or conflicting users.
            throw new \RuntimeException('RouterOS provisioning outcome is unknown; reconcile before retry.');
        }

        // Encrypt before persistence; plaintext remains only in this call's memory.
        try {
            $encryptedCredential = $this->cipher->encrypt($context->tenantId, $voucher->id, $request->username, $request->passwordForProvisioning());
            $voucher->markProvisioned($encryptedCredential, $request->expiresAt);
            $order->fulfill($voucher->id);
            $this->repository->completeProvisioning($voucher, $order);
            // If persistence/complete fails after RouterOS success, retain the claim for reconciliation.
            $this->idempotency->complete($key);
        } catch (\Throwable) {
            throw new \RuntimeException('Router accepted voucher provisioning, but durable completion failed; reconcile before retry.');
        }

        return new VoucherProvisioningResult($voucher->id, $voucher->status());
    }
}
