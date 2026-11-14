<?php

declare(strict_types=1);

namespace App\Service\Provider\Payment;

use App\Repository\PaymentAttemptRepository;
use App\Entity\PaymentAttempt;
use App\Entity\TenantContext;

/** Safe default until the Doctrine adapter and tenant-scoped query are installed. */
final class UnavailablePaymentAttemptRepository implements PaymentAttemptRepository
{
    public function findForTenant(TenantContext $context, string $attemptId): ?PaymentAttempt
    {
        throw new PaymentPersistenceUnavailable();
    }

    public function save(TenantContext $context, PaymentAttempt $attempt): void
    {
        throw new PaymentPersistenceUnavailable();
    }
}
