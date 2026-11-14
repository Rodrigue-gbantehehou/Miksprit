<?php

declare(strict_types=1);

namespace App\Service\Provider\Payment;

final class PaymentPersistenceUnavailable extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Payment persistence is unavailable until a durable repository is configured.');
    }
}
