<?php

declare(strict_types=1);

namespace App\Service\Provider\Router;

final class RouterOsUnavailable extends RouterOsException
{
    public function __construct()
    {
        parent::__construct('RouterOS transport is not configured; no router operation was performed.');
    }
}
