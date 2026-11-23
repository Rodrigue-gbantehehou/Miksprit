<?php

declare(strict_types=1);

namespace App\Service\Router;

use App\Entity\HotspotSession;
use App\Entity\HotspotSessionStatus;
use App\Entity\Router;
use App\Entity\RouterStatus;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/** Persist tenant-owned RouterOS session snapshots without treating a failed poll as a disconnect. */
final readonly class SyncRouterHotspotSessions
{
    public function __construct(
        private Connection $connection,
        private EntityManagerInterface $entities,
        private RouterManagementFactory $routers,
    ) {}

    /** @return array{routers:int,failed:int,started:int,updated:int,ended:int} */
    public function execute(int $limit = 100): array
    {
        if ($limit < 1 || $limit > 1000) throw new \InvalidArgumentException('Router session synchronization limit is invalid.');
        $routerIds = $this->connection->fetchFirstColumn(
            "SELECT id FROM routers WHERE status = 'active' ORDER BY id LIMIT ".$limit,
        );
        $summary = ['routers' => 0, 'failed' => 0, 'started' => 0, 'updated' => 0, 'ended' => 0];
        foreach ($routerIds as $routerId) {
            $router = $this->entities->find(Router::class, (string) $routerId);
            if (!$router instanceof Router || $router->status() !== RouterStatus::Active) continue;
            try {
                $snapshot = $this->routers->forRouterManagement($router)->activeSessions();
            } catch (\Throwable) {
                // Never mark sessions ended when the router could not be polled.
                ++$summary['failed'];
                $this->entities->clear();
                continue;
            }

            $summary['routers']++;
            $now = new \DateTimeImmutable();
            $seenExternalIds = [];
            foreach ($snapshot as $remote) {
                $externalId = $remote['externalId'] ?? null;
                $username = $remote['username'] ?? null;
                if (!is_string($externalId) || !is_string($username) || $username === '') continue;
                $seenExternalIds[$externalId] = true;
                $voucherId = $this->connection->fetchOne(
                    "SELECT id FROM vouchers WHERE tenant_id = ? AND router_id = ? AND router_username = ? AND status = 'active'",
                    [$router->tenantId()->value, $router->id, $username],
                );
                if (!is_string($voucherId)) continue;

                $existingId = $this->connection->fetchOne(
                    "SELECT id FROM hotspot_sessions WHERE router_id = ? AND external_session_id = ? AND state = 'active' ORDER BY created_at DESC LIMIT 1",
                    [$router->id, $externalId],
                );
                if (is_string($existingId)) {
                    $session = $this->entities->find(HotspotSession::class, $existingId);
                    if (!$session instanceof HotspotSession) continue;
                    $session->recordAccounting($remote['bytesIn'] ?? null, $remote['bytesOut'] ?? null);
                    ++$summary['updated'];
                } else {
                    $session = new HotspotSession(bin2hex(random_bytes(16)), $router->tenantId(), $router->id, $voucherId, $externalId, $now);
                    $session->confirmStarted($now);
                    $session->recordAccounting($remote['bytesIn'] ?? null, $remote['bytesOut'] ?? null);
                    $this->entities->persist($session);
                    ++$summary['started'];
                }
            }

            $active = $this->entities->createQueryBuilder()
                ->select('session')
                ->from(HotspotSession::class, 'session')
                ->where('session.routerId = :routerId')
                ->andWhere('session.sessionStatus = :state')
                ->setParameter('routerId', $router->id)
                ->setParameter('state', HotspotSessionStatus::Active)
                ->getQuery()->getResult();
            foreach ($active as $session) {
                if ($session instanceof HotspotSession && !isset($seenExternalIds[$session->externalSessionId])) {
                    $session->end(HotspotSessionStatus::Ended, $now);
                    ++$summary['ended'];
                }
            }
            $this->entities->flush();
            $this->entities->clear();
        }
        return $summary;
    }
}
