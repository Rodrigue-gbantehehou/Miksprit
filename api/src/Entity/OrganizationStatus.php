<?php

declare(strict_types=1);

namespace App\Entity;

enum OrganizationStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
}
