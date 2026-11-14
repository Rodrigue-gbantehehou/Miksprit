<?php

declare(strict_types=1);

namespace App\Entity;

enum VoucherStatus: string
{
    case Reserved = 'reserved';
    case Active = 'active';
    case Expired = 'expired';
    case Revoked = 'revoked';
}
