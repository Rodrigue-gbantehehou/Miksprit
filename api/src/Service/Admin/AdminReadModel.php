<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Entity\AdminAuditEvent;
use App\Repository\AdminAuditEventStore;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

final readonly class AdminReadModel
{
    public function __construct(private Connection $connection, private EntityManagerInterface $entityManager, private AdminAuditEventStore $audit) {}

    /** @return array{tenants: int, activeUsers: int, activeRouters: int, paymentsTodayXof: int, pendingOutbox: int} */
    public function summary(): array
    {
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d H:i:s');
        $tomorrow = (new \DateTimeImmutable('tomorrow'))->format('Y-m-d H:i:s');
        return [
            'tenants' => (int) $this->connection->fetchOne("SELECT COUNT(*) FROM organizations WHERE organization_status = 'active'"),
            'activeUsers' => (int) $this->connection->fetchOne('SELECT COUNT(*) FROM users WHERE active = 1'),
            'activeRouters' => (int) $this->connection->fetchOne("SELECT COUNT(*) FROM routers WHERE status = 'active'"),
            'paymentsTodayXof' => (int) $this->connection->fetchOne("SELECT COALESCE(SUM(p.expected_minor_units), 0) FROM payment_attempts p JOIN wifi_orders o ON o.tenant_id = p.tenant_id AND o.public_reference = p.order_reference WHERE p.state = 'succeeded' AND o.created_at >= ? AND o.created_at < ?", [$today, $tomorrow]),
            'pendingOutbox' => (int) $this->connection->fetchOne("SELECT COUNT(*) FROM payment_outbox WHERE state = 'pending'"),
        ];
    }

    /** @return list<array{id: string, name: string, slug: string, status: string, createdAt: string}> */
    public function tenants(): array
    {
        return $this->connection->fetchAllAssociative('SELECT tenant_id AS id, name, slug, organization_status AS status, created_at AS createdAt FROM organizations ORDER BY created_at DESC LIMIT 500');
    }

    /** @return list<array{id: string, name: string, tenantName: string, siteName: string, status: string, version?: string}> */
    public function routers(): array
    {
        return $this->connection->fetchAllAssociative("SELECT r.id, r.router_name AS name, o.name AS tenantName, s.site_name AS siteName, r.status, r.router_os_version AS version FROM routers r JOIN organizations o ON o.tenant_id = r.tenant_id JOIN sites s ON s.id = r.site_id AND s.tenant_id = r.tenant_id ORDER BY r.created_at DESC LIMIT 500");
    }

    /** @return list<array{id: string, reference: string, tenantName: string, amountXof: int, currency: string, status: string, createdAt: string}> */
    public function payments(): array
    {
        return $this->connection->fetchAllAssociative("SELECT p.id, p.order_reference AS reference, o.name AS tenantName, p.expected_minor_units AS amountXof, p.currency, p.state AS status, w.created_at AS createdAt FROM payment_attempts p JOIN organizations o ON o.tenant_id = p.tenant_id JOIN wifi_orders w ON w.public_reference = p.order_reference AND w.tenant_id = p.tenant_id ORDER BY w.created_at DESC LIMIT 500");
    }

    /** @return list<array{id: string, tenantName: string, entryType: string, amountXof: int, currency: string, createdAt: string}> */
    public function ledger(): array
    {
        return $this->connection->fetchAllAssociative("SELECT l.id, o.name AS tenantName, l.entry_type AS entryType, l.amount_minor AS amountXof, l.currency, l.occurred_at AS createdAt FROM ledger_entries l JOIN organizations o ON o.tenant_id = l.tenant_id ORDER BY l.occurred_at DESC LIMIT 500");
    }

    /** @return list<array{id: string, actor: string, action: string, target: string, createdAt: string}> */
    public function audit(): array
    {
        return array_map(static fn (AdminAuditEvent $event): array => [
            'id' => $event->id,
            'actor' => $event->actorUserId,
            'action' => $event->action,
            'target' => $event->target,
            'createdAt' => $event->createdAt->format(DATE_ATOM),
        ], $this->audit->latest());
    }

    /** @return list<array{name: string, state: string, observedAt: string}> */
    public function operations(): array
    {
        $now = (new \DateTimeImmutable())->format(DATE_ATOM);
        try {
            $this->connection->executeQuery('SELECT 1')->fetchOne();
            $dbState = 'healthy';
        } catch (\Throwable) {
            $dbState = 'unavailable';
        }
        $outboxState = 'unknown';
        if ($dbState === 'healthy') {
            try {
                $counts = $this->connection->fetchAssociative("SELECT SUM(state = 'pending') AS pending, SUM(state = 'processing') AS processing, SUM(state = 'failed') AS failed FROM payment_outbox");
                $pending = (int) ($counts['pending'] ?? 0);
                $processing = (int) ($counts['processing'] ?? 0);
                $failed = (int) ($counts['failed'] ?? 0);
                $outboxState = $failed > 0 ? 'failed' : ($processing > 0 ? 'processing' : ($pending > 0 ? 'pending' : 'idle'));
            } catch (\Throwable) {
                $outboxState = 'unavailable';
            }
        }

        return [
            ['name' => 'database', 'state' => $dbState, 'observedAt' => $now],
            ['name' => 'payment_outbox', 'state' => $outboxState, 'observedAt' => $now],
            ['name' => 'paid_order_worker', 'state' => 'available_cron_required', 'observedAt' => $now],
        ];
    }

    /** @return list<array{id: string, email: string, active: bool, roles: list<string>}> */
    public function users(): array
    {
        $users = $this->entityManager->getConnection()->fetchAllAssociative('SELECT u.id, u.email, u.active, u.roles FROM users u ORDER BY u.created_at DESC LIMIT 500');
        return array_map(static function (array $row): array {
            $roles = json_decode((string) $row['roles'], true);
            return ['id' => (string) $row['id'], 'email' => (string) $row['email'], 'active' => (bool) $row['active'], 'roles' => is_array($roles) ? array_values(array_filter($roles, 'is_string')) : []];
        }, $users);
    }
}
