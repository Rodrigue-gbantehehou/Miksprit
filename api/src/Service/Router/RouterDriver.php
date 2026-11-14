<?php

declare(strict_types=1);

namespace App\Service\Router;

use App\Service\Provider\Router\RouterOsCredentials;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag(self::TAG)]
interface RouterDriver
{
    public const TAG = 'app.wifi_router_driver';

    /** Stable lowercase provider identifier persisted on Router. */
    public function code(): string;

    /** Construct a management client using a tenant-scoped target and short-lived credentials. */
    public function connect(RouterProvisioningTarget $target, RouterOsCredentials $credentials): RouterManagementPort;
}
