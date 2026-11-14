<?php

declare(strict_types=1);

namespace App\Service\Provider\Payment;

use App\Repository\PaymentConfirmationStore;
use App\Entity\VerifiedPaymentStatus;

/** Explicit fallback retained for tests; DoctrinePaymentConfirmationStore is the default alias. */
final class UnavailablePaymentConfirmationStore implements PaymentConfirmationStore
{
    public function apply(VerifiedPaymentStatus $status): void
    {
        throw new PaymentConfirmationUnavailable();
    }
}
