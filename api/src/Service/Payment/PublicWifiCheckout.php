<?php

declare(strict_types=1);

namespace App\Service\Payment;

final readonly class PublicWifiCheckout
{
    public function __construct(public string $orderReference, public string $provider, public PaymentSession $session)
    {
    }
}
