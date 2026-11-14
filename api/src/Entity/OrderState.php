<?php

declare(strict_types=1);

namespace App\Entity;

enum OrderState: string
{
    case PendingPayment = 'pending_payment';
    case Paid = 'paid';
    case AccessPending = 'access_pending';
    case Fulfilled = 'fulfilled';
    case PaymentFailed = 'payment_failed';
    case ProvisioningFailed = 'provisioning_failed';
}
