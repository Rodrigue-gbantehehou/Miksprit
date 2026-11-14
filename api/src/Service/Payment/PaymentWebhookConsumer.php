<?php

declare(strict_types=1);

namespace App\Service\Payment;

use App\Event\PaymentWebhookEvent;

interface PaymentWebhookConsumer
{
    /** Webhook content is untrusted; implementations must verify transaction status server-to-server. */
    public function consume(PaymentWebhookEvent $event): void;
}
