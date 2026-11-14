<?php

declare(strict_types=1);

namespace App\Service\Router;

interface HotspotProvisioningPort
{
    /** Returns only after RouterOS replies !done; traps and transport errors must throw. */
    public function createUser(string $username, string $password, string $profile, ?string $limitUptime, ?int $limitBytes = null, int $sharedUsers = 1): void;
}
