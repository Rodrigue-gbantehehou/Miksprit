<?php

declare(strict_types=1);

namespace App\Service\Provider\Router;

final readonly class RouterOsReply
{
    /** @param list<array<string, string>> $records */
    public function __construct(public RouterOsReplyStatus $status, public ?string $safeErrorCode = null, public array $records = [])
    {
        if ($safeErrorCode !== null && preg_match('/^[A-Z0-9_]{1,64}$/', $safeErrorCode) !== 1) {
            throw new \InvalidArgumentException('Reply error code must be a safe uppercase identifier.');
        }
        foreach ($records as $record) {
            foreach ($record as $key => $value) {
                if (!is_string($key) || !is_string($value) || str_contains($key, "\0") || str_contains($value, "\0")) {
                    throw new \InvalidArgumentException('RouterOS reply records must contain strings without null bytes.');
                }
            }
        }
    }
}
