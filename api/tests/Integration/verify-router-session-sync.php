<?php

declare(strict_types=1);

use App\Entity\HotspotSession;
use App\Entity\Router;
use App\Entity\TenantId;
use App\Entity\Voucher;
use App\Service\Router\RouterManagementFactory;
use App\Service\Router\SyncRouterHotspotSessions;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;

require dirname(__DIR__, 2).'/vendor/autoload.php';

final class SessionSyncFakeRouter implements \App\Service\Router\RouterManagementPort
{
    public array $snapshot = [];
    public bool $unavailable = false;
    public function health(): array { return ['version' => '7.16', 'uptime' => '1d', 'identity' => 'test']; }
    public function activeSessions(): array { if ($this->unavailable) throw new RuntimeException('router unavailable'); return $this->snapshot; }
    public function disconnectSession(string $externalId): bool { return true; }
    public function revokeUser(string $username): bool { return true; }
    public function createUser(string $username, string $password, string $profile, ?string $limitUptime, ?int $limitBytes = null, int $sharedUsers = 1): void {}
}

final class SessionSyncFakeFactory implements RouterManagementFactory
{
    public function __construct(public SessionSyncFakeRouter $router) {}
    public function forRouterManagement(Router $router): \App\Service\Router\RouterManagementPort { return $this->router; }
}

$config = ORMSetup::createAttributeMetadataConfig([dirname(__DIR__, 2).'/src/Entity'], true);
$config->enableNativeLazyObjects(true);
$connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);
$entities = new EntityManager($connection, $config);
(new SchemaTool($entities))->createSchema(array_map($entities->getClassMetadata(...), [Router::class, Voucher::class, HotspotSession::class]));
$tenantId = new TenantId('tenant_session_01');
$router = new Router('router_session_01', $tenantId, 'site_session_01', 'Session router', 'router.example.test', 8729, 'tenant_session_01:router-123456');
$router->markReachable('7.16', new DateTimeImmutable());
$voucher = new Voucher('voucher_session_01', $tenantId, 'site_session_01', hash('sha256', 'session'), routerId: $router->id);
$voucher->markProvisioned('encrypted-voucher-credential', new DateTimeImmutable('+1 hour'), 'w123456789012345');
$entities->persist($router);
$entities->persist($voucher);
$entities->flush();
$entities->clear();

$fakeRouter = new SessionSyncFakeRouter();
$fakeRouter->snapshot = [['externalId' => '*A1', 'username' => 'w123456789012345', 'address' => '192.0.2.8', 'macAddress' => null, 'uptime' => '2m', 'bytesIn' => '100', 'bytesOut' => '200']];
$sync = new SyncRouterHotspotSessions($connection, $entities, new SessionSyncFakeFactory($fakeRouter));
$first = $sync->execute();
if ($first['started'] !== 1 || (int) $connection->fetchOne("SELECT COUNT(*) FROM hotspot_sessions WHERE state = 'active'") !== 1) {
    throw new RuntimeException('The first router snapshot was not persisted as an active tenant session.');
}
$fakeRouter->snapshot = [];
$second = $sync->execute();
if ($second['ended'] !== 1 || (int) $connection->fetchOne("SELECT COUNT(*) FROM hotspot_sessions WHERE state = 'ended'") !== 1) {
    throw new RuntimeException('A confirmed session disappearance was not persisted as ended.');
}
$fakeRouter->snapshot = [['externalId' => '*A1', 'username' => 'w123456789012345', 'address' => null, 'macAddress' => null, 'uptime' => '2m', 'bytesIn' => '100', 'bytesOut' => '200']];
$sync->execute();
$fakeRouter->unavailable = true;
$failed = $sync->execute();
if ($failed['failed'] !== 1 || (int) $connection->fetchOne("SELECT COUNT(*) FROM hotspot_sessions WHERE state = 'active'") !== 1) {
    throw new RuntimeException('A failed router poll incorrectly closed a persisted active session.');
}

echo "Router session snapshot persistence, end detection, and offline safety proof passed.\n";
