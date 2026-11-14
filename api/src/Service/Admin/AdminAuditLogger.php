<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Entity\AdminAuditEvent;
use App\Repository\AdminAuditEventStore;
use App\Entity\User;

final readonly class AdminAuditLogger
{
    public function __construct(private AdminAuditEventStore $events) {}

    public function record(User $actor, string $action, string $target): void
    {
        $this->events->append(new AdminAuditEvent(bin2hex(random_bytes(16)), $actor->id(), $action, $target, new \DateTimeImmutable()));
    }
}
