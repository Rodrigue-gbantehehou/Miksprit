<?php

declare(strict_types=1);

namespace App\Service\Router;

use App\Entity\Router;

interface RouterManagementFactory
{
    public function forRouterManagement(Router $router): RouterManagementPort;
}
