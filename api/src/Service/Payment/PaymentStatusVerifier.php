<?php

declare(strict_types=1);

namespace App\Service\Payment;

use App\Entity\VerifiedPaymentStatus;

interface PaymentStatusVerifier
{
    /** Fetches the transaction directly from the named provider and returns normalized evidence. */
    public function verify(string $provider, string $providerReference): VerifiedPaymentStatus;
}
