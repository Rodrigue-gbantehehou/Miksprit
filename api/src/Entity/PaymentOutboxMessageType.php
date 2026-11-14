<?php

declare(strict_types=1);

namespace App\Entity;

enum PaymentOutboxMessageType: string
{
    case ProvisionPaidOrder = 'provision_paid_order';
}
