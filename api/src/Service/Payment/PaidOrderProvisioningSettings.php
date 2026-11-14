<?php

declare(strict_types=1);

namespace App\Service\Payment;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class PaidOrderProvisioningSettings
{
    public function __construct(#[Autowire('%env(ROUTEROS_HOTSPOT_PROFILE)%')] public string $hotspotProfile)
    {
        if (trim($hotspotProfile) === '' || strlen($hotspotProfile) > 128 || preg_match('/[\x00-\x1F\x7F]/', $hotspotProfile) === 1) {
            throw new \InvalidArgumentException('ROUTEROS_HOTSPOT_PROFILE is invalid.');
        }
    }
}
