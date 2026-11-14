<?php

declare(strict_types=1);

namespace App\Repository;

final class TenantMembershipPersistenceUnavailable extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Tenant membership persistence is unavailable until a durable repository is configured.');
    }
}
