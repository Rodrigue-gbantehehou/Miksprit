<?php

declare(strict_types=1);

namespace App\Service\Provider\Router;

enum RouterOsReplyStatus: string
{
    case DONE = 'done';
    case TRAP = 'trap';
}
