<?php

declare(strict_types=1);

namespace App\Service\Provider\Router;

/** Network/protocol failure: the command may already have been applied. Never retry automatically. */
final class RouterOsTransportFailure extends RouterOsException
{
    public function __construct()
    {
        parent::__construct('RouterOS transport failed; command outcome may be unknown.');
    }
}
