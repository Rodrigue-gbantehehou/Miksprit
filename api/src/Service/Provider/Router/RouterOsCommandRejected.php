<?php

declare(strict_types=1);

namespace App\Service\Provider\Router;

/** A RouterOS !trap reply: the command was definitively rejected, not left in an unknown state. */
final class RouterOsCommandRejected extends RouterOsException
{
}
