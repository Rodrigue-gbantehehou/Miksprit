<?php

declare(strict_types=1);

namespace App\Service\Router;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/** Strict registry: unknown or duplicate provider codes never fall back to another kit. */
final readonly class RouterDriverRegistry
{
    /** @var array<string, RouterDriver> */
    private array $drivers;

    /** @param iterable<RouterDriver> $drivers */
    public function __construct(#[AutowireIterator(RouterDriver::TAG)] iterable $drivers)
    {
        $indexed = [];
        foreach ($drivers as $driver) {
            $code = $driver->code();
            if (preg_match('/^[a-z][a-z0-9_-]{1,31}$/D', $code) !== 1 || isset($indexed[$code])) {
                throw new \LogicException('Router driver codes must be valid and unique.');
            }
            $indexed[$code] = $driver;
        }
        $this->drivers = $indexed;
    }

    public function get(string $providerCode): RouterDriver
    {
        return $this->drivers[$providerCode] ?? throw new UnknownRouterDriver($providerCode);
    }

    /** @return list<string> */
    public function codes(): array
    {
        return array_keys($this->drivers);
    }
}
