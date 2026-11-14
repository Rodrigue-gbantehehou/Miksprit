<?php

declare(strict_types=1);

namespace App\Service\Router;

use App\Service\Provider\Router\RouterOsConnection;

/** Internal-only RouterOS connection material. Never serialize or expose from API responses. */
final readonly class RouterProvisioningTarget
{
    public function __construct(private RouterOsConnection $connection, private string $credentialReference, private string $providerCode)
    {
        if ($credentialReference === '' || strlen($credentialReference) > 160
            || preg_match('/^[a-z][a-z0-9_-]{1,31}$/D', $providerCode) !== 1) {
            throw new \InvalidArgumentException('Router provisioning target is invalid.');
        }
    }

    public function connection(): RouterOsConnection
    {
        return $this->connection;
    }

    public function credentialReference(): string
    {
        return $this->credentialReference;
    }

    public function __debugInfo(): array
    {
        return ['connection' => '[internal]', 'credentialReference' => '[redacted]', 'providerCode' => $this->providerCode];
    }

    public function providerCode(): string
    {
        return $this->providerCode;
    }

    public function __serialize(): array
    {
        throw new \LogicException('Router provisioning targets must never be serialized.');
    }
}
