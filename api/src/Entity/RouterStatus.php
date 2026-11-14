<?php

declare(strict_types=1);

namespace App\Entity;

enum RouterStatus: string
{
    case PendingEnrollment = 'pending_enrollment';
    case Active = 'active';
    case Offline = 'offline';
    case Suspended = 'suspended';
}
