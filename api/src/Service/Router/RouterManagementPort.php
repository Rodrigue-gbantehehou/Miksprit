<?php

declare(strict_types=1);

namespace App\Service\Router;

interface RouterManagementPort extends HotspotProvisioningPort
{
    /** @return array{version: string, uptime: string, identity: string} */
    public function health(): array;

    /** @return list<array{externalId: string, username: string, address: ?string, macAddress: ?string, uptime: ?string, bytesIn: ?string, bytesOut: ?string}> */
    public function activeSessions(): array;

    public function disconnectSession(string $externalId): bool;

    public function revokeUser(string $username): bool;
}
