<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AdminAuditEvent;

interface AdminAuditEventStore
{
    public function append(AdminAuditEvent $event): void;

    /** @return list<AdminAuditEvent> */
    public function latest(int $limit = 100): array;
}
