<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Embeddable]
final readonly class TenantId
{
    public function __construct(#[ORM\Column(name: 'tenant_id', type: 'string', length: 80)] public string $value)
    {
        if (!preg_match('/^[A-Za-z0-9_-]{8,80}$/D', $value)) {
            throw new \InvalidArgumentException('Tenant ID must be an opaque identifier between 8 and 80 characters.');
        }
    }

    public function equals(self $other): bool
    {
        return hash_equals($this->value, $other->value);
    }
}
