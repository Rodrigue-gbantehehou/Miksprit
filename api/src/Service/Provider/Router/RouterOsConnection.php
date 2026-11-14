<?php

declare(strict_types=1);

namespace App\Service\Provider\Router;

/** Explicit, validated endpoint settings. TLS certificate verification is mandatory. */
final readonly class RouterOsConnection
{
    public function __construct(
        public string $host,
        public int $port = 8729,
        public int $connectTimeoutSeconds = 3,
        public int $operationTimeoutSeconds = 10,
    ) {
        $host = trim($this->host);
        if ($host === '' || $host !== $this->host || str_contains($host, '://') || preg_match('~[\s/\\\\]~', $host) === 1) {
            throw new \InvalidArgumentException('RouterOS host must be a hostname or IP address, without a scheme, path, or whitespace.');
        }
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6) === false
            && preg_match('/^(?=.{1,253}$)(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)(?:\.(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?))*\.?$/', $host) !== 1) {
            throw new \InvalidArgumentException('RouterOS host is not a valid hostname or IP address.');
        }
        $packedIp = filter_var($host, FILTER_VALIDATE_IP) !== false ? inet_pton($host) : false;
        $isIpv4Loopback = is_string($packedIp) && strlen($packedIp) === 4 && ord($packedIp[0]) === 127;
        $isIpv6Loopback = is_string($packedIp) && strlen($packedIp) === 16 && substr($packedIp, 0, 15) === str_repeat("\0", 15) && ord($packedIp[15]) === 1;
        if (strtolower(rtrim($host, '.')) === 'localhost' || $isIpv4Loopback || $isIpv6Loopback) {
            throw new \InvalidArgumentException('Loopback endpoints are not allowed for RouterOS connections.');
        }
        if ($this->port < 1 || $this->port > 65535) {
            throw new \InvalidArgumentException('RouterOS port must be between 1 and 65535.');
        }
        if ($this->connectTimeoutSeconds < 1 || $this->connectTimeoutSeconds > 10) {
            throw new \InvalidArgumentException('Connect timeout must be between 1 and 10 seconds.');
        }
        if ($this->operationTimeoutSeconds < 1 || $this->operationTimeoutSeconds > 60) {
            throw new \InvalidArgumentException('Operation timeout must be between 1 and 60 seconds.');
        }
    }
}
