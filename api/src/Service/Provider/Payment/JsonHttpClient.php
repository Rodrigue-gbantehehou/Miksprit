<?php

declare(strict_types=1);

namespace App\Service\Provider\Payment;

interface JsonHttpClient
{
    /** @param array<string, string> $headers @param array<string, mixed>|null $json */
    public function request(string $method, string $url, array $headers = [], ?array $json = null): JsonHttpResponse;
}
