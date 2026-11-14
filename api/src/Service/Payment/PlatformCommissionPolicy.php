<?php

declare(strict_types=1);

namespace App\Service\Payment;

/** Server-owned commission policy. An unset rate disables checkout until the operator agrees to pricing. */
final readonly class PlatformCommissionPolicy
{
    private ?int $basisPoints;

    public function __construct(string $basisPoints)
    {
        $basisPoints = trim($basisPoints);
        if ($basisPoints === '' || $basisPoints === '__UNCONFIGURED__') {
            $this->basisPoints = null;
            return;
        }
        if (!ctype_digit($basisPoints) || (int) $basisPoints > 10_000) {
            throw new \InvalidArgumentException('PLATFORM_COMMISSION_BPS must be an integer between 0 and 10000, or remain unconfigured.');
        }
        $this->basisPoints = (int) $basisPoints;
    }

    public function isConfigured(): bool
    {
        return $this->basisPoints !== null;
    }

    public function basisPoints(): int
    {
        return $this->basisPoints ?? throw new PublicPaymentUnavailable('The platform commission rate is not configured.');
    }

    public function amountFor(int $grossMinorUnits): int
    {
        if ($grossMinorUnits < 1) {
            throw new \InvalidArgumentException('Commission requires a positive sale amount.');
        }

        return intdiv($grossMinorUnits * $this->basisPoints(), 10_000);
    }
}
