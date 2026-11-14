<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PaymentAttempt;
use App\Entity\TenantContext;

/** Every lookup is tenant-scoped in the persistence adapter. */
interface PaymentAttemptRepository
{
    public function findForTenant(TenantContext $context, string $attemptId): ?PaymentAttempt;

    public function save(TenantContext $context, PaymentAttempt $attempt): void;
}
