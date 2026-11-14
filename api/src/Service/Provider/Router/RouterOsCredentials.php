<?php

declare(strict_types=1);

namespace App\Service\Provider\Router;

/** Credentials are deliberately not printable or serializable. Keep them out of logs and queues. */
final readonly class RouterOsCredentials
{
    public function __construct(private string $username, private string $password)
    {
        if (trim($this->username) === '' || $this->password === '') {
            throw new \InvalidArgumentException('RouterOS credentials must not be empty.');
        }
        if (str_contains($this->username, "\0") || str_contains($this->password, "\0")) {
            throw new \InvalidArgumentException('RouterOS credentials contain an invalid byte.');
        }
    }

    public function __debugInfo(): array
    {
        return ['username' => '[redacted]', 'password' => '[redacted]'];
    }

    public function username(): string
    {
        return $this->username;
    }

    public function password(): string
    {
        return $this->password;
    }

    public function __serialize(): array
    {
        throw new \LogicException('RouterOS credentials must never be serialized.');
    }
}
