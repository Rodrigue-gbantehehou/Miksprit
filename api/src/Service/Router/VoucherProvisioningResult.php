<?php

declare(strict_types=1);

namespace App\Service\Router;

use App\Entity\VoucherStatus;

final readonly class VoucherProvisioningResult
{
    public function __construct(public string $voucherId, public VoucherStatus $status)
    {
    }
}
