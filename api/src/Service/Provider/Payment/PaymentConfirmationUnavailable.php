<?php

declare(strict_types=1);

namespace App\Service\Provider\Payment;

final class PaymentConfirmationUnavailable extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Payment confirmation storage and transactional outbox are not configured.');
    }
}
