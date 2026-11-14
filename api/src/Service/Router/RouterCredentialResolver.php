<?php

declare(strict_types=1);

namespace App\Service\Router;

use App\Service\Provider\Router\RouterOsCredentials;

/** Resolve a secret-manager reference just before opening a RouterOS TLS connection. */
interface RouterCredentialResolver
{
    public function resolve(string $credentialReference): RouterOsCredentials;
}
