<?php

declare(strict_types=1);

namespace App\Service\Provider\Router;

interface RouterOsTransport
{
    /**
     * Connect using TLS with peer and hostname verification enabled, bounded by both configured timeouts.
     * Implementations must not log credentials, sentence words, or raw RouterOS replies.
     */
    public function execute(RouterOsConnection $connection, RouterOsCredentials $credentials, RouterOsSentence $sentence): RouterOsReply;
}
