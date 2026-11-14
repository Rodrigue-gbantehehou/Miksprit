<?php

declare(strict_types=1);

namespace App\Service\Provider\Router;

/** Safe default until a real TLS-verified RouterOS transport is configured. */
final class UnavailableRouterOsTransport implements RouterOsTransport
{
    public function execute(RouterOsConnection $connection, RouterOsCredentials $credentials, RouterOsSentence $sentence): RouterOsReply
    {
        throw new RouterOsUnavailable();
    }
}
