<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\XofAmount;

/** Evidence from a server-side provider status lookup, never from browser input. */
final readonly class VerifiedPaymentStatus
{
    public function __construct(
        public string $provider,
        public string $providerReference,
        public string $merchantReference,
        public XofAmount $amount,
        public string $currency,
        public PaymentState $state,
        public \DateTimeImmutable $observedAt,
        public ?int $providerFeeMinorUnits = null,
        public ?int $amountTransferredMinorUnits = null,
        public bool $settled = false,
        public ?string $tenantId = null,
    ) {
        foreach ([$provider, $providerReference, $merchantReference] as $value) {
            if ($value === '' || strlen($value) > 200) {
                throw new \InvalidArgumentException('Provider and references must be present and bounded.');
            }
        }
        if ($tenantId !== null && (trim($tenantId) === '' || strlen($tenantId) > 80)) throw new \InvalidArgumentException('Payment tenant identifier is invalid.');
        if ($currency !== 'XOF') {
            throw new \InvalidArgumentException('Only XOF is supported.');
        }
        if (($providerFeeMinorUnits !== null && $providerFeeMinorUnits < 0)
            || ($amountTransferredMinorUnits !== null && $amountTransferredMinorUnits < 0)
            || ($settled && ($providerFeeMinorUnits === null || $amountTransferredMinorUnits === null))) {
            throw new \InvalidArgumentException('Settled payment evidence must contain non-negative provider fee and transferred amount.');
        }
    }
}
