<?php

declare(strict_types=1);

namespace App\Service\Router;

final class UnknownRouterDriver extends \RuntimeException
{
    public function __construct(string $providerCode)
    {
        parent::__construct('No Wi-Fi router driver is registered for provider code "'.substr($providerCode, 0, 32).'".');
    }
}
