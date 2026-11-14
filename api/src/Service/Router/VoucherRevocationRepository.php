<?php

declare(strict_types=1);

namespace App\Service\Router;

use App\Entity\TenantContext;
use App\Entity\Voucher;

interface VoucherRevocationRepository extends VoucherProvisioningRepository
{
    public function encryptedCredentialForTenant(TenantContext $context, string $voucherId): ?string;

    public function completeRevocation(Voucher $voucher): void;
}
