<?php

declare(strict_types=1);

namespace App\Service\Router;

use App\Entity\Router;
use App\Entity\Site;
use App\Entity\TenantContext;
use App\Entity\Voucher;
use App\Entity\WifiOrder;

/** Adapters must scope both lookups to the supplied authenticated tenant. */
interface VoucherProvisioningRepository
{
    public function findRouterForTenant(TenantContext $context, string $routerId): ?Router;

    public function findSiteForTenant(TenantContext $context, string $siteId): ?Site;

    public function findVoucherForTenant(TenantContext $context, string $voucherId): ?Voucher;

    public function findOrderForTenantByPublicReference(TenantContext $context, string $publicReference): ?WifiOrder;

    /** Atomically persists voucher activation and order fulfillment after rechecking current states. */
    public function completeProvisioning(Voucher $voucher, WifiOrder $order): void;
}
