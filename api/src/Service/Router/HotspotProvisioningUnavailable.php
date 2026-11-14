<?php

declare(strict_types=1);

namespace App\Service\Router;

final class HotspotProvisioningUnavailable extends \RuntimeException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('RouterOS provisioning is unavailable until a verified transport and credential resolver are configured.', 0, $previous);
    }
}
