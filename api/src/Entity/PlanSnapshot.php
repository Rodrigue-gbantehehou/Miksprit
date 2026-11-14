<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

use App\Entity\XofAmount;
use App\Entity\TenantId;

/** Immutable commercial terms copied into an order at purchase time. */
#[ORM\Embeddable]
final readonly class PlanSnapshot
{
    public function __construct(
        #[ORM\Column(name: 'plan_id', type: 'string', length: 80)]
        public string $planId,
        #[ORM\Column(name: 'site_id', type: 'string', length: 80)]
        public string $siteId,
        #[ORM\Embedded(class: TenantId::class, columnPrefix: 'plan_')]
        public TenantId $tenantId,
        #[ORM\Column(name: 'plan_version', type: 'integer')]
        public int $version,
        #[ORM\Column(name: 'plan_name', type: 'string', length: 120)]
        public string $name,
        #[ORM\Embedded(class: XofAmount::class, columnPrefix: 'plan_price_')]
        public XofAmount $price,
        #[ORM\Column(name: 'session_seconds', type: 'integer')]
        public int $sessionSeconds,
        #[ORM\Column(name: 'data_limit_bytes', type: 'bigint', nullable: true)]
        public ?int $dataLimitBytes,
        #[ORM\Column(name: 'device_limit', type: 'smallint')]
        public int $deviceLimit,
    ) {
        if ($planId === '' || $siteId === '' || $version < 1 || trim($name) === ''
            || $price->minorUnits < 1 || $sessionSeconds < 1 || $deviceLimit < 1
            || ($dataLimitBytes !== null && $dataLimitBytes < 1)) {
            throw new \InvalidArgumentException('Plan snapshot contains invalid commercial terms.');
        }
    }
}
