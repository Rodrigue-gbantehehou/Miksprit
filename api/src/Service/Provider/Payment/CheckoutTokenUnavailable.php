<?php

declare(strict_types=1);

namespace App\Service\Provider\Payment;

final class CheckoutTokenUnavailable extends PaymentProviderUnavailable
{
    public function __construct(public readonly string $providerReference, ?\Throwable $previous = null)
    {
        parent::__construct('The provider transaction exists but its hosted payment token is unavailable.', previous: $previous);
    }
}
