<?php

declare(strict_types=1);

namespace App\Service\Owner;

use App\Entity\AccessPlan;
use App\Entity\PlanPublicationState;
use App\Entity\Router;
use App\Entity\Site;
use App\Entity\SiteStatus;
use App\Entity\TenantContext;
use App\Entity\TenantPermission;
use App\Entity\XofAmount;
use App\Service\Router\InspectRouter;
use App\Service\Router\RouterDriverRegistry;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

final readonly class OwnerWriteService
{
    public function __construct(
        private EntityManagerInterface $entities,
        private Connection $connection,
        private RouterDriverRegistry $routerDrivers,
        private InspectRouter $routerInspector,
    ) {}

    /** @return array{id: string, name: string, slug: string, status: string} */
    public function createSite(TenantContext $context, string $name, string $slug): array
    {
        $this->assert($context, TenantPermission::ManageSites);
        if (trim($name) === '' || mb_strlen($name) > 120 || preg_match('/^[a-z0-9](?:[a-z0-9-]{0,78}[a-z0-9])?$/D', $slug) !== 1) {
            throw new \InvalidArgumentException('Site name or slug is invalid.');
        }
        if ($this->connection->fetchOne('SELECT 1 FROM sites WHERE tenant_id = ? AND slug = ?', [$context->tenantId->value, $slug])) {
            throw new \DomainException('Site slug is already in use.');
        }
        $id = bin2hex(random_bytes(16));
        $site = new Site($id, $context->tenantId, $name, $slug, hash('sha256', random_bytes(32)));
        $this->entities->persist($site);
        try { $this->entities->flush(); }
        catch (UniqueConstraintViolationException $exception) { throw new \DomainException('Site slug is already in use.', previous: $exception); }
        return ['id' => $id, 'name' => $site->name(), 'slug' => $slug, 'status' => $site->status()->value];
    }

    /** @return array{id: string, name: string, status: string} */
    public function renameSite(TenantContext $context, string $id, string $name): array
    {
        $this->assert($context, TenantPermission::ManageSites);
        $this->assertOwned('sites', $id, $context);
        $site = $this->entities->find(Site::class, $id);
        if (!$site instanceof Site) throw new \DomainException('Site not found.');
        $site->rename($name);
        $this->entities->flush();
        return ['id' => $id, 'name' => $site->name(), 'status' => $site->status()->value];
    }

    /** @return array{id: string, name: string, slug: string, status: string} */
    public function changeSiteStatus(TenantContext $context, string $id, string $status): array
    {
        $this->assert($context, TenantPermission::ManageSites);
        $this->assertOwned('sites', $id, $context);
        $site = $this->entities->find(Site::class, $id);
        if (!$site instanceof Site) throw new \DomainException('Site not found.');
        if ($status === 'active') {
            if ($site->status() === SiteStatus::Suspended) $site->reactivate();
            else $site->activate();
        } elseif ($status === 'suspended') {
            $site->suspend();
        } elseif ($status === 'draft') {
            throw new \InvalidArgumentException('An active or suspended site cannot return to draft.');
        } else {
            throw new \InvalidArgumentException('Unsupported site status.');
        }
        $this->entities->flush();
        return ['id' => $id, 'name' => $site->name(), 'slug' => $site->slug, 'status' => $site->status()->value];
    }

    /** @param array{name: string, siteId: string, priceXof: int, sessionSeconds: int, dataLimitBytes: ?int, deviceLimit: int} $terms
     * @return array{id: string, name: string, status: string, version: int}
     */
    public function createPlan(TenantContext $context, array $terms): array
    {
        $this->assert($context, TenantPermission::ManagePlans);
        $siteId = $this->requiredString($terms['siteId'] ?? null, 80);
        $this->assertOwned('sites', $siteId, $context);
        $plan = new AccessPlan(bin2hex(random_bytes(16)), $context->tenantId, $siteId,
            $this->requiredString($terms['name'] ?? null, 120),
            new XofAmount($this->positiveInt($terms['priceXof'] ?? null)),
            $this->positiveInt($terms['sessionSeconds'] ?? null),
            $this->nullablePositiveInt($terms['dataLimitBytes'] ?? null),
            $this->positiveInt($terms['deviceLimit'] ?? 1));
        $this->entities->persist($plan);
        $this->entities->flush();
        return $this->planResult($plan);
    }

    /** @param array{name: string, priceXof: int, sessionSeconds: int, dataLimitBytes: ?int, deviceLimit: int} $terms
     * @return array{id: string, name: string, status: string, version: int}
     */
    public function updatePlan(TenantContext $context, string $id, array $terms): array
    {
        $this->assert($context, TenantPermission::ManagePlans);
        $this->assertOwned('access_plans', $id, $context);
        $plan = $this->entities->find(AccessPlan::class, $id);
        if (!$plan instanceof AccessPlan) throw new \DomainException('Plan not found.');
        $plan->updateTerms($this->requiredString($terms['name'] ?? null, 120),
            new XofAmount($this->positiveInt($terms['priceXof'] ?? null)),
            $this->positiveInt($terms['sessionSeconds'] ?? null),
            $this->nullablePositiveInt($terms['dataLimitBytes'] ?? null),
            $this->positiveInt($terms['deviceLimit'] ?? 1));
        $this->entities->flush();
        return $this->planResult($plan);
    }

    /** @return array{id: string, name: string, status: string, version: int} */
    public function changePlanState(TenantContext $context, string $id, string $state): array
    {
        $this->assert($context, TenantPermission::ManagePlans);
        $this->assertOwned('access_plans', $id, $context);
        $plan = $this->entities->find(AccessPlan::class, $id);
        if (!$plan instanceof AccessPlan) throw new \DomainException('Plan not found.');
        if ($state === 'published') $plan->publish();
        elseif ($state === 'draft') $plan->unpublish();
        elseif ($state === 'archived') $plan->archive();
        else throw new \InvalidArgumentException('Unsupported plan state.');
        $this->entities->flush();
        return $this->planResult($plan);
    }

    /** @return array{id: string, name: string, status: string} */
    public function registerRouter(TenantContext $context, string $name, string $siteId, string $host, int $port, string $credentialReference, string $providerCode = 'mikrotik'): array
    {
        $this->assert($context, TenantPermission::ManageRouters);
        $this->assertOwned('sites', $siteId, $context);
        $credentialReference = $this->requiredString($credentialReference, 160);
        if (!str_starts_with($credentialReference, $context->tenantId->value.':')) {
            throw new \InvalidArgumentException('The router credential reference must belong to the selected tenant.');
        }
        if (!in_array($providerCode, $this->routerDrivers->codes(), true)) {
            throw new \InvalidArgumentException('The selected Wi-Fi kit is not installed.');
        }
        $router = new Router(bin2hex(random_bytes(16)), $context->tenantId, $siteId,
            $this->requiredString($name, 120), trim($host), $port, $credentialReference, providerCode: $providerCode);
        $this->entities->persist($router);
        try { $this->entities->flush(); }
        catch (UniqueConstraintViolationException $exception) { throw new \DomainException('Router name is already in use at this site.', previous: $exception); }
        return ['id' => $router->id, 'name' => $name, 'status' => $router->status()->value];
    }

    /** @return array{id: string, status: string} */
    public function suspendRouter(TenantContext $context, string $id): array
    {
        $this->assert($context, TenantPermission::ManageRouters);
        $this->assertOwned('routers', $id, $context);
        $router = $this->entities->find(Router::class, $id);
        if (!$router instanceof Router) throw new \DomainException('Router not found.');
        $router->suspend();
        $this->entities->flush();
        return ['id' => $id, 'status' => $router->status()->value];
    }

    /** Reactivate only after the tenant-scoped router answered a live health request. */
    public function verifyRouter(TenantContext $context, string $id): array
    {
        $this->assert($context, TenantPermission::ManageRouters);
        $this->assertOwned('routers', $id, $context);
        $health = $this->routerInspector->execute($context, $id);
        $router = $this->entities->find(Router::class, $id);
        if (!$router instanceof Router || !$router->tenantId()->equals($context->tenantId)) throw new \DomainException('Router not found.');
        $router->markReachable($health['version'], new \DateTimeImmutable());
        $this->entities->flush();
        return ['id' => $id, 'status' => $router->status()->value, 'version' => $health['version'], 'identity' => $health['identity']];
    }

    private function assert(TenantContext $context, TenantPermission $permission): void
    {
        if (!$context->allows($permission)) throw new \DomainException('Access denied for this tenant operation.');
    }

    private function assertOwned(string $table, string $id, TenantContext $context): void
    {
        if (!in_array($table, ['sites', 'access_plans', 'routers'], true) || trim($id) === '' || strlen($id) > 80
            || !$this->connection->fetchOne("SELECT 1 FROM {$table} WHERE id = ? AND tenant_id = ?", [$id, $context->tenantId->value])) {
            throw new \DomainException('Resource not found in this tenant.');
        }
    }

    /** @return array{id: string, name: string, status: string, version: int} */
    private function planResult(AccessPlan $plan): array
    {
        $data = $this->connection->fetchAssociative('SELECT name, publication_state, version FROM access_plans WHERE id = ?', [$plan->id]);
        return ['id' => $plan->id, 'name' => (string) $data['name'], 'status' => $plan->publicationState()->value, 'version' => (int) $data['version']];
    }

    private function requiredString(mixed $value, int $max): string
    {
        if (!is_string($value) || trim($value) === '' || mb_strlen(trim($value)) > $max) throw new \InvalidArgumentException('A required text field is invalid.');
        return trim($value);
    }

    private function positiveInt(mixed $value): int
    {
        if (!is_int($value) || $value < 1 || $value > 2_147_483_647) throw new \InvalidArgumentException('A positive integer field is invalid.');
        return $value;
    }

    private function nullablePositiveInt(mixed $value): ?int
    {
        return $value === null ? null : $this->positiveInt($value);
    }
}
