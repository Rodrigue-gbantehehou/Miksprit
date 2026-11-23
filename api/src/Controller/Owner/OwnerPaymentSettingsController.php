<?php

declare(strict_types=1);

namespace App\Controller\Owner;

use App\Entity\User;
use App\Service\Auth\CsrfCookieManager;
use App\Service\Owner\OwnerTenantContextUnavailable;
use App\Service\Owner\OwnerTenantSelectionRequired;
use App\Service\Owner\ResolveOwnerContext;
use App\Service\Payment\TenantPaymentSettings;
use App\Service\Payment\PaymentProviderRegistry;
use App\Service\Provider\Payment\PaymentProviderUnavailable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/owner/payment-settings', name: 'api_owner_payment_settings_')]
final class OwnerPaymentSettingsController extends AbstractController
{
    public function __construct(
        private readonly ResolveOwnerContext $contexts,
        private readonly TenantPaymentSettings $settings,
        private readonly PaymentProviderRegistry $providers,
    ) {
    }

    #[Route('', name: 'read', methods: ['GET'])]
    public function read(Request $request): JsonResponse
    {
        return $this->withTenant($request, fn ($tenant) => [
            'availableProviders' => array_map(static fn (string $code): array => ['code' => $code], $this->providers->codes()),
            'providerEnabled' => in_array('fedapay', $this->providers->codes(), true) && $this->providers->tenantFeatureEnabled('fedapay'),
            'configuration' => $this->settings->read($tenant),
        ]);
    }

    #[Route('', name: 'save', methods: ['PUT'])]
    public function save(Request $request, CsrfCookieManager $csrf): JsonResponse
    {
        $actor = $this->getUser();
        if (!$actor instanceof User) return $this->json(['error' => 'Authentication required.'], 401, ['Cache-Control' => 'no-store']);
        try { $data = $request->toArray(); } catch (\Throwable) {
            return $this->json(['error' => 'A valid JSON body is required.'], 400, ['Cache-Control' => 'no-store']);
        }
        if (!$csrf->isValid($request, $data)) return $this->json(['error' => 'Invalid CSRF token.'], 403, ['Cache-Control' => 'no-store']);
        return $this->withTenant($request, function ($tenant) use ($data): array {
            $this->settings->read($tenant); // Authorize before any outbound call.
            $provider = is_string($data['provider'] ?? null) ? $data['provider'] : '';
            $environment = is_string($data['environment'] ?? null) ? $data['environment'] : '';
            $apiKey = is_string($data['apiKey'] ?? null) ? trim($data['apiKey']) : '';
            $webhookSecret = is_string($data['webhookSecret'] ?? null) ? trim($data['webhookSecret']) : '';
            if ($provider !== 'fedapay' || !in_array($environment, ['test', 'live'], true) || $apiKey === '' || $webhookSecret === '') {
                throw new \InvalidArgumentException('Choisissez FedaPay et renseignez les identifiants et le mode correspondants.');
            }
            $this->providers->testTenantCredentials($provider, ['apiKey' => $apiKey, 'webhookSecret' => $webhookSecret, 'environment' => $environment]);
            return ['configuration' => $this->settings->save(
                $tenant,
                $provider,
                $environment,
                $apiKey,
                $webhookSecret,
            )];
        }, 200);
    }

    #[Route('/test', name: 'test', methods: ['POST'])]
    public function test(Request $request, CsrfCookieManager $csrf): JsonResponse
    {
        $actor = $this->getUser();
        if (!$actor instanceof User) return $this->json(['error' => 'Authentication required.'], 401, ['Cache-Control' => 'no-store']);
        try { $data = $request->toArray(); } catch (\Throwable) {
            return $this->json(['error' => 'A valid JSON body is required.'], 400, ['Cache-Control' => 'no-store']);
        }
        if (!$csrf->isValid($request, $data)) return $this->json(['error' => 'Invalid CSRF token.'], 403, ['Cache-Control' => 'no-store']);
        return $this->withTenant($request, function ($tenant) use ($data): array {
            $this->settings->read($tenant); // Enforce the same tenant permission before testing secrets.
            $provider = is_string($data['provider'] ?? null) ? $data['provider'] : '';
            $environment = is_string($data['environment'] ?? null) ? $data['environment'] : '';
            $apiKey = is_string($data['apiKey'] ?? null) ? trim($data['apiKey']) : '';
            $webhookSecret = is_string($data['webhookSecret'] ?? null) ? trim($data['webhookSecret']) : '';
            if ($provider !== 'fedapay' || !in_array($environment, ['test', 'live'], true) || $apiKey === '' || $webhookSecret === '') {
                throw new \InvalidArgumentException('Choisissez FedaPay et renseignez les identifiants et le mode correspondants.');
            }
            $this->providers->testTenantCredentials($provider, ['apiKey' => $apiKey, 'webhookSecret' => $webhookSecret, 'environment' => $environment]);
            return ['valid' => true];
        });
    }

    #[Route('', name: 'revoke', methods: ['DELETE'])]
    public function revoke(Request $request, CsrfCookieManager $csrf): JsonResponse
    {
        $actor = $this->getUser();
        if (!$actor instanceof User) return $this->json(['error' => 'Authentication required.'], 401, ['Cache-Control' => 'no-store']);
        try { $data = $request->toArray(); } catch (\Throwable) {
            return $this->json(['error' => 'A valid JSON body is required.'], 400, ['Cache-Control' => 'no-store']);
        }
        if (!$csrf->isValid($request, $data)) return $this->json(['error' => 'Invalid CSRF token.'], 403, ['Cache-Control' => 'no-store']);
        return $this->withTenant($request, function ($tenant): array {
            $this->settings->revoke($tenant);
            return ['configuration' => ['provider' => null, 'environment' => null, 'configured' => false, 'lastApiCheckAt' => null, 'updatedAt' => null]];
        });
    }

    private function withTenant(Request $request, callable $operation, int $successStatus = 200): JsonResponse
    {
        $actor = $this->getUser();
        if (!$actor instanceof User) return $this->json(['error' => 'Authentication required.'], 401, ['Cache-Control' => 'no-store']);
        try {
            $tenant = $this->contexts->execute($actor->id(), $request->headers->get('X-Tenant-ID'));
            return $this->json($operation($tenant), $successStatus, ['Cache-Control' => 'no-store, private']);
        } catch (OwnerTenantSelectionRequired) {
            return $this->json(['error' => 'Organization selection is required.', 'code' => 'tenant_selection_required'], 409, ['Cache-Control' => 'no-store, private']);
        } catch (OwnerTenantContextUnavailable) {
            return $this->json(['error' => 'An active organization is required.', 'code' => 'owner_tenant_unavailable'], 403, ['Cache-Control' => 'no-store, private']);
        } catch (\InvalidArgumentException $exception) {
            return $this->json(['error' => $exception->getMessage()], 422, ['Cache-Control' => 'no-store, private']);
        } catch (\DomainException $exception) {
            $message = $exception->getMessage();
            $conflict = str_contains($message, 'paiements sont encore') || str_contains($message, 'Révoquez');
            $status = str_contains($message, 'Access denied') ? 403 : ($conflict ? 409 : 404);
            return $this->json(['error' => $status === 403 ? 'Access denied.' : ($status === 409 ? $message : 'Tenant context not found.')], $status, ['Cache-Control' => 'no-store, private']);
        } catch (\LogicException) {
            return $this->json(['error' => 'Payment credential encryption is not configured.'], 503, ['Cache-Control' => 'no-store, private']);
        } catch (PaymentProviderUnavailable) {
            return $this->json(['error' => 'FedaPay n’a pas validé la configuration ou son API est temporairement indisponible.'], 503, ['Cache-Control' => 'no-store, private']);
        }
    }
}
