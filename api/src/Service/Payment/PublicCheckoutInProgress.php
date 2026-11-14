<?php

declare(strict_types=1);

namespace App\Service\Payment;

final class PublicCheckoutInProgress extends \RuntimeException
{
    public function __construct(public readonly string $orderReference, ?\Throwable $previous = null)
    {
        parent::__construct('This checkout request has already created an order and is being reconciled.', previous: $previous);
    }
}
