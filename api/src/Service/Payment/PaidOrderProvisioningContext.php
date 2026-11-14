<?php

declare(strict_types=1);

namespace App\Service\Payment;

use App\Entity\Router;
use App\Entity\WifiOrder;

final readonly class PaidOrderProvisioningContext
{
    public function __construct(public WifiOrder $order, public Router $router)
    {
    }
}
