<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Append-only record of an administrative read or action; never stores request bodies or secrets. */
#[ORM\Entity]
#[ORM\Table(name: 'admin_audit_events')]
#[ORM\Index(name: 'idx_admin_audit_created', columns: ['created_at'])]
final class AdminAuditEvent
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 32)]
        public readonly string $id,
        #[ORM\Column(name: 'actor_user_id', type: 'string', length: 36)]
        public readonly string $actorUserId,
        #[ORM\Column(type: 'string', length: 80)]
        public readonly string $action,
        #[ORM\Column(type: 'string', length: 160)]
        public readonly string $target,
        #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
        public readonly \DateTimeImmutable $createdAt,
    ) {
        if (preg_match('/^[a-f0-9]{32}$/D', $id) !== 1 || trim($actorUserId) === ''
            || strlen($actorUserId) > 36 || trim($action) === '' || strlen($action) > 80
            || trim($target) === '' || strlen($target) > 160) {
            throw new \InvalidArgumentException('Administrative audit event is invalid.');
        }
    }
}
