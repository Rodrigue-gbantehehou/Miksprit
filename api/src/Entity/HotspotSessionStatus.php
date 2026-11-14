<?php

declare(strict_types=1);

namespace App\Entity;

enum HotspotSessionStatus: string
{
    case Queued = 'queued';
    case Active = 'active';
    case Ended = 'ended';
    case Disconnected = 'disconnected';
    case Failed = 'failed';
}
