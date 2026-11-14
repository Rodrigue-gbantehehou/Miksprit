<?php

declare(strict_types=1);

namespace App\Entity;

enum SiteStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Suspended = 'suspended';
}
