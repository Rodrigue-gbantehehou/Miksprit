<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** XOF has no fractional unit; amounts are represented as integer francs. */
#[ORM\Embeddable]
final readonly class XofAmount
{
    public function __construct(#[ORM\Column(type: 'integer')] public int $minorUnits)
    {
        if ($minorUnits < 0) {
            throw new \InvalidArgumentException('XOF amount cannot be negative.');
        }
    }

    public function equals(self $other): bool
    {
        return $this->minorUnits === $other->minorUnits;
    }
}
