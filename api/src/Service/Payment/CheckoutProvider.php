<?php

declare(strict_types=1);

namespace App\Service\Payment;

/** Provider port used by application use cases; adapters must honor the stable idempotency key. */
interface CheckoutProvider
{
    public function createCheckout(PaymentIntent $intent, string $idempotencyKey): PaymentSession;
}
