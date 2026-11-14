<?php

declare(strict_types=1);

namespace App\Service\Provider\Payment;

final readonly class JsonHttpResponse
{
    /** @param array<string, mixed> $data */
    public function __construct(public int $statusCode, public array $data)
    {
    }
}
