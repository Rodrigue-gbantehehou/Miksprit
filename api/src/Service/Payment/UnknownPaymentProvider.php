<?php

declare(strict_types=1);

namespace App\Service\Payment;

final class UnknownPaymentProvider extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Payment provider is not registered.');
    }
}
