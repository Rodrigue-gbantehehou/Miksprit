<?php

declare(strict_types=1);

namespace App\Entity;

enum PaymentState: string
{
    case Initiated = 'initiated';
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Expired = 'expired';
}
