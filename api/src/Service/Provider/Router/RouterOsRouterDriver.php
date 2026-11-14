<?php

declare(strict_types=1);

namespace App\Service\Provider\Router;

use App\Service\Router\RouterDriver;
use App\Service\Router\RouterManagementPort;
use App\Service\Router\RouterProvisioningTarget;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(index: self::CODE)]
final readonly class RouterOsRouterDriver implements RouterDriver
{
    public const CODE = 'mikrotik';

    public function __construct(private RouterOsTransport $transport)
    {
    }

    public function code(): string
    {
        return self::CODE;
    }

    public function connect(RouterProvisioningTarget $target, RouterOsCredentials $credentials): RouterManagementPort
    {
        return new RouterOsHotspotProvisioner($this->transport, $target->connection(), $credentials);
    }
}
