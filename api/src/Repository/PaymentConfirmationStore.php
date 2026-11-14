<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\VerifiedPaymentStatus;

interface PaymentConfirmationStore
{
    /**
     * Atomically correlate the verified merchant reference, update its attempt, and enqueue fulfillment
     * in a durable outbox only on the first transition to succeeded.
     */
    public function apply(VerifiedPaymentStatus $status): void;
}
