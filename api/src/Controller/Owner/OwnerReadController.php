<?php

declare(strict_types=1);

namespace App\Controller\Owner;

use App\Entity\User;
use App\Service\Owner\OwnerReadService;
use App\Service\Owner\CreateOwnerOrganization;
use App\Service\Owner\OwnerOrganizationConflict;
use App\Service\Owner\OwnerTenantContextUnavailable;
use App\Service\Owner\OwnerWriteService;
use App\Service\Auth\CsrfCookieManager;
use App\Service\Owner\OwnerTenantSelectionRequired;
use App\Service\Owner\ResolveOwnerContext;
use App\Service\Router\RouterDriverRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/owner', name: 'api_owner_')]
final class OwnerReadController extends AbstractController
{
    public function __construct(
        private readonly ResolveOwnerContext $contexts,
        private readonly OwnerReadService $reads,
        private readonly OwnerWriteService $writes,
        private readonly RouterDriverRegistry $routerDrivers,
    ) {
    }

    #[Route('/dashboard', name: 'dashboard', methods: ['GET'])]
    public function dashboard(Request $request): JsonResponse
    {
        return $this->respond($request, fn ($context) => $this->reads->dashboard($context));
    }

    #[Route('/sites', name: 'sites', methods: ['GET'])]
    public function sites(Request $request): JsonResponse
    {
        return $this->respond($request, fn ($context) => $this->reads->sites($context));
    }

    #[Route('/plans', name: 'plans', methods: ['GET'])]
    public function plans(Request $request): JsonResponse
    {
        return $this->respond($request, fn ($context) => $this->reads->plans($context));
    }

    #[Route('/routers', name: 'routers', methods: ['GET'])]
    public function routers(Request $request): JsonResponse
    {
        return $this->respond($request, fn ($context) => $this->reads->routers($context));
    }

    #[Route('/router-drivers', name: 'router_drivers', methods: ['GET'])]
    public function routerDrivers(Request $request): JsonResponse
    {
        return $this->respond($request, fn ($context) => array_map(static fn (string $code): array => ['code' => $code], $this->routerDrivers->codes()));
    }

    #[Route('/transactions', name: 'transactions', methods: ['GET'])]
    public function transactions(Request $request): JsonResponse
    {
        return $this->respond($request, fn ($context) => $this->reads->transactions($context));
    }

    #[Route('/organizations', name: 'organizations', methods: ['GET'])]
    public function organizations(): JsonResponse
    {
        $actor = $this->getUser();
        if (!$actor instanceof User) {
            return $this->json(['error' => 'Authentication required.'], 401, ['Cache-Control' => 'no-store']);
        }

        return $this->json($this->reads->organizationsForActor($actor->id()), 200, ['Cache-Control' => 'no-store, private']);
    }

    #[Route('/organizations', name: 'organization_create', methods: ['POST'])]
    public function createOrganization(Request $request, CsrfCookieManager $csrf, CreateOwnerOrganization $create): JsonResponse
    {
        $actor = $this->getUser();
        if (!$actor instanceof User) {
            return $this->json(['error' => 'Authentication required.'], 401, ['Cache-Control' => 'no-store']);
        }
        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->json(['error' => 'Une requête JSON valide est nécessaire.'], 400, ['Cache-Control' => 'no-store']);
        }
        if (!$csrf->isValid($request, $data)) {
            return $this->json(['error' => 'Jeton de sécurité invalide.'], 403, ['Cache-Control' => 'no-store']);
        }

        try {
            $organization = $create->execute(
                $actor->id(),
                is_string($data['name'] ?? null) ? $data['name'] : '',
                is_string($data['slug'] ?? null) ? $data['slug'] : '',
            );
        } catch (\InvalidArgumentException $exception) {
            return $this->json(['error' => $exception->getMessage()], 422, ['Cache-Control' => 'no-store']);
        } catch (OwnerOrganizationConflict $exception) {
            return $this->json(['error' => $exception->getMessage()], 409, ['Cache-Control' => 'no-store']);
        }

        return $this->json($organization, 201, ['Cache-Control' => 'no-store, private']);
    }

    #[Route('/sites', name: 'site_create', methods: ['POST'])]
    public function createSite(Request $request, CsrfCookieManager $csrf): JsonResponse
    {
        return $this->mutate($request, $csrf, fn ($context, array $data) => $this->writes->createSite($context,
            is_string($data['name'] ?? null) ? $data['name'] : '', is_string($data['slug'] ?? null) ? $data['slug'] : ''), 201);
    }

    #[Route('/sites/{id}', name: 'site_update', methods: ['PATCH'])]
    public function updateSite(string $id, Request $request, CsrfCookieManager $csrf): JsonResponse
    {
        return $this->mutate($request, $csrf, fn ($context, array $data) => $this->writes->renameSite($context, $id,
            is_string($data['name'] ?? null) ? $data['name'] : ''));
    }

    #[Route('/sites/{id}/status', name: 'site_status', methods: ['PATCH'])]
    public function siteStatus(string $id, Request $request, CsrfCookieManager $csrf): JsonResponse
    {
        return $this->mutate($request, $csrf, fn ($context, array $data) => $this->writes->changeSiteStatus($context, $id,
            is_string($data['status'] ?? null) ? $data['status'] : ''));
    }

    #[Route('/plans', name: 'plan_create', methods: ['POST'])]
    public function createPlan(Request $request, CsrfCookieManager $csrf): JsonResponse
    {
        return $this->mutate($request, $csrf, fn ($context, array $data) => $this->writes->createPlan($context, $this->planTerms($data)), 201);
    }

    #[Route('/plans/{id}', name: 'plan_update', methods: ['PATCH'])]
    public function updatePlan(string $id, Request $request, CsrfCookieManager $csrf): JsonResponse
    {
        return $this->mutate($request, $csrf, fn ($context, array $data) => $this->writes->updatePlan($context, $id, $this->planTerms($data)));
    }

    #[Route('/plans/{id}/status', name: 'plan_status', methods: ['PATCH'])]
    public function planStatus(string $id, Request $request, CsrfCookieManager $csrf): JsonResponse
    {
        return $this->mutate($request, $csrf, fn ($context, array $data) => $this->writes->changePlanState($context, $id,
            is_string($data['status'] ?? null) ? $data['status'] : ''));
    }

    #[Route('/routers', name: 'router_register', methods: ['POST'])]
    public function registerRouter(Request $request, CsrfCookieManager $csrf): JsonResponse
    {
        return $this->mutate($request, $csrf, fn ($context, array $data) => $this->writes->registerRouter($context,
            is_string($data['name'] ?? null) ? $data['name'] : '', is_string($data['siteId'] ?? null) ? $data['siteId'] : '',
            is_string($data['host'] ?? null) ? $data['host'] : '', !array_key_exists('apiPort', $data) ? 8729 : (is_int($data['apiPort']) ? $data['apiPort'] : 0),
            is_string($data['credentialReference'] ?? null) ? $data['credentialReference'] : '',
            is_string($data['providerCode'] ?? null) ? $data['providerCode'] : 'mikrotik'), 201);
    }

    #[Route('/routers/{id}/status', name: 'router_status', methods: ['PATCH'])]
    public function routerStatus(string $id, Request $request, CsrfCookieManager $csrf): JsonResponse
    {
        return $this->mutate($request, $csrf, function ($context, array $data) use ($id): array {
            if (($data['status'] ?? null) !== 'suspended') throw new \InvalidArgumentException('Owners can only suspend a router; reactivation requires a verified health check.');
            return $this->writes->suspendRouter($context, $id);
        });
    }

    #[Route('/routers/{id}/verify', name: 'router_verify', methods: ['POST'])]
    public function verifyRouter(string $id, Request $request, CsrfCookieManager $csrf): JsonResponse
    {
        return $this->mutate($request, $csrf, fn ($context) => $this->writes->verifyRouter($context, $id));
    }

    /** @param callable(\App\Entity\TenantContext, array<string, mixed>): array<string, mixed> $operation */
    private function mutate(Request $request, CsrfCookieManager $csrf, callable $operation, int $successStatus = 200): JsonResponse
    {
        $actor = $this->getUser();
        if (!$actor instanceof User) return $this->json(['error' => 'Authentication required.'], 401, ['Cache-Control' => 'no-store']);
        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->json(['error' => 'A valid JSON body is required.'], 400, ['Cache-Control' => 'no-store']);
        }
        if (!$csrf->isValid($request, $data)) return $this->json(['error' => 'Invalid CSRF token.'], 403, ['Cache-Control' => 'no-store']);
        try {
            $context = $this->contexts->execute($actor->id(), $request->headers->get('X-Tenant-ID'));
            return $this->json($operation($context, $data), $successStatus, ['Cache-Control' => 'no-store, private']);
        } catch (OwnerTenantSelectionRequired) {
            return $this->json(['error' => 'Organization selection is required.', 'code' => 'tenant_selection_required'], 409, ['Cache-Control' => 'no-store, private']);
        } catch (OwnerTenantContextUnavailable) {
            return $this->json(['error' => 'Aucun espace actif n’est associé à ce compte. Créez votre espace Miksprit depuis la console propriétaire.', 'code' => 'owner_tenant_unavailable'], 403, ['Cache-Control' => 'no-store, private']);
        } catch (\InvalidArgumentException $exception) {
            return $this->json(['error' => $exception->getMessage()], 422, ['Cache-Control' => 'no-store, private']);
        } catch (\DomainException $exception) {
            $message = $exception->getMessage();
            $status = str_contains($message, 'Access denied') ? 403 : (str_contains($message, 'already in use') ? 409 : 404);
            return $this->json(['error' => $status === 403 ? 'Access denied.' : $message], $status, ['Cache-Control' => 'no-store, private']);
        }
    }

    /** @param array<string, mixed> $data
     * @return array{name: string, siteId: string, priceXof: int, sessionSeconds: int, dataLimitBytes: ?int, deviceLimit: int}
     */
    private function planTerms(array $data): array
    {
        return [
            'name' => is_string($data['name'] ?? null) ? $data['name'] : '',
            'siteId' => is_string($data['siteId'] ?? null) ? $data['siteId'] : '',
            'priceXof' => is_int($data['priceXof'] ?? null) ? $data['priceXof'] : 0,
            'sessionSeconds' => is_int($data['sessionSeconds'] ?? null) ? $data['sessionSeconds'] : 0,
            'dataLimitBytes' => is_int($data['dataLimitBytes'] ?? null) ? $data['dataLimitBytes'] : (($data['dataLimitBytes'] ?? null) === null ? null : 0),
            'deviceLimit' => !array_key_exists('deviceLimit', $data) ? 1 : (is_int($data['deviceLimit']) ? $data['deviceLimit'] : 0),
        ];
    }

    private function respond(Request $request, callable $operation): JsonResponse
    {
        $actor = $this->getUser();
        if (!$actor instanceof User) {
            return $this->json(['error' => 'Authentication required.'], 401, ['Cache-Control' => 'no-store']);
        }

        try {
            $context = $this->contexts->execute($actor->id(), $request->headers->get('X-Tenant-ID'));
            return $this->json($operation($context), 200, ['Cache-Control' => 'no-store, private']);
        } catch (OwnerTenantSelectionRequired) {
            return $this->json(['error' => 'Organization selection is required.', 'code' => 'tenant_selection_required'], 409, ['Cache-Control' => 'no-store, private']);
        } catch (OwnerTenantContextUnavailable) {
            return $this->json(['error' => 'Aucun espace actif n’est associé à ce compte. Créez votre espace Miksprit depuis la console propriétaire.', 'code' => 'owner_tenant_unavailable'], 403, ['Cache-Control' => 'no-store, private']);
        } catch (\DomainException $exception) {
            $status = str_contains($exception->getMessage(), 'Access denied') ? 403 : 404;
            return $this->json(['error' => $status === 403 ? 'Access denied.' : 'Tenant context not found.'], $status, ['Cache-Control' => 'no-store, private']);
        }
    }
}
