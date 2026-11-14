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
    ) {
        foreach ([$provider, $providerReference, $merchantReference] as $value) {
            if ($value === '' || strlen($value) > 200) {
                throw new \InvalidArgumentException('Provider and references must be present and bounded.');
            }
        }
        if ($currency !== 'XOF') {
            throw new \InvalidArgumentException('Only XOF is supported.');
        }
    }
}
