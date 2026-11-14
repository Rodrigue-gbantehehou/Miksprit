<?php

declare(strict_types=1);

namespace App\Service\Router;

use App\Service\Provider\Router\RouterOsCredentials;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/** Reads referenced RouterOS secrets injected by the deployment secret store. */
#[AsAlias(RouterCredentialResolver::class)]
final readonly class EnvironmentRouterCredentialResolver implements RouterCredentialResolver
{
    /** @param array<string, array{username?: mixed, password?: mixed}> $credentials */
    public function __construct(private array $credentials) {}

    public function resolve(string $credentialReference): RouterOsCredentials
    {
        $secret = $this->credentials[$credentialReference] ?? null;
        if (!is_array($secret) || !is_string($secret['username'] ?? null) || !is_string($secret['password'] ?? null)) {
            throw new HotspotProvisioningUnavailable();
        }
        try {
            return new RouterOsCredentials($secret['username'], $secret['password']);
        } catch (\InvalidArgumentException $exception) {
            throw new HotspotProvisioningUnavailable(previous: $exception);
        }
    }
}
