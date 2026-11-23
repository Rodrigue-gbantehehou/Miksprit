<?php

declare(strict_types=1);

namespace App\Controller\Owner;

use App\Entity\User;
use App\Service\Auth\CsrfCookieManager;
use App\Service\Owner\OwnerMembershipService;
use App\Service\Owner\OwnerTenantContextUnavailable;
use App\Service\Owner\OwnerTenantSelectionRequired;
use App\Service\Owner\ResolveOwnerContext;
use App\Service\Subscription\TenantSubscriptionAccess;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/owner/members', name: 'api_owner_members_')]
final class OwnerMembershipController extends AbstractController
{
    public function __construct(private readonly ResolveOwnerContext $contexts, private readonly OwnerMembershipService $members, private readonly TenantSubscriptionAccess $subscriptionAccess) {}

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        return $this->read($request, fn ($context) => ['items' => $this->members->list($context)]);
    }

    #[Route('', name: 'invite', methods: ['POST'])]
    public function invite(Request $request, CsrfCookieManager $csrf): JsonResponse
    {
        return $this->write($request, $csrf, fn ($context, array $data) => $this->members->invite(
            $context, is_string($data['email'] ?? null) ? $data['email'] : '', is_string($data['role'] ?? null) ? $data['role'] : '',
        ), 201);
    }

    #[Route('/{id}/role', name: 'role', methods: ['PATCH'])]
    public function role(string $id, Request $request, CsrfCookieManager $csrf): JsonResponse
    {
        return $this->write($request, $csrf, fn ($context, array $data) => $this->members->changeRole(
            $context, $id, is_string($data['role'] ?? null) ? $data['role'] : '',
        ));
    }

    #[Route('/{id}/status', name: 'status', methods: ['PATCH'])]
    public function status(string $id, Request $request, CsrfCookieManager $csrf): JsonResponse
    {
        return $this->write($request, $csrf, fn ($context, array $data) => $this->members->setStatus(
            $context, $id, is_string($data['status'] ?? null) ? $data['status'] : '',
        ));
    }

    private function read(Request $request, callable $operation): JsonResponse
    {
        $actor = $this->getUser();
        if (!$actor instanceof User) return $this->json(['error' => 'Authentication required.'], 401, ['Cache-Control' => 'no-store']);
        try {
            $context = $this->contexts->execute($actor->id(), $request->headers->get('X-Tenant-ID'));
            return $this->json($operation($context), 200, ['Cache-Control' => 'no-store, private']);
        } catch (OwnerTenantSelectionRequired) {
            return $this->json(['error' => 'Organization selection is required.'], 409, ['Cache-Control' => 'no-store, private']);
        } catch (OwnerTenantContextUnavailable) {
            return $this->json(['error' => 'An active organization is required.'], 403, ['Cache-Control' => 'no-store, private']);
        } catch (\DomainException $exception) {
            return $this->json(['error' => str_contains($exception->getMessage(), 'Access denied') ? 'Access denied.' : 'Tenant context not found.'], str_contains($exception->getMessage(), 'Access denied') ? 403 : 404, ['Cache-Control' => 'no-store, private']);
        }
    }

    private function write(Request $request, CsrfCookieManager $csrf, callable $operation, int $successStatus = 200): JsonResponse
    {
        $actor = $this->getUser();
        if (!$actor instanceof User) return $this->json(['error' => 'Authentication required.'], 401, ['Cache-Control' => 'no-store']);
        try { $data = $request->toArray(); } catch (\Throwable) { return $this->json(['error' => 'A valid JSON body is required.'], 400, ['Cache-Control' => 'no-store']); }
        if (!$csrf->isValid($request, $data)) return $this->json(['error' => 'Invalid CSRF token.'], 403, ['Cache-Control' => 'no-store']);
        try {
            $context = $this->contexts->execute($actor->id(), $request->headers->get('X-Tenant-ID'));
            $this->subscriptionAccess->assertMayOperate($context->tenantId);
            return $this->json($operation($context, $data), $successStatus, ['Cache-Control' => 'no-store, private']);
        } catch (OwnerTenantSelectionRequired) {
            return $this->json(['error' => 'Organization selection is required.'], 409, ['Cache-Control' => 'no-store, private']);
        } catch (OwnerTenantContextUnavailable) {
            return $this->json(['error' => 'An active organization is required.'], 403, ['Cache-Control' => 'no-store, private']);
        } catch (\InvalidArgumentException $exception) {
            return $this->json(['error' => $exception->getMessage()], 422, ['Cache-Control' => 'no-store, private']);
        } catch (\DomainException $exception) {
            $message = $exception->getMessage();
            $status = str_contains($message, 'Access denied') ? 403 : (str_contains($message, 'abonnement Miksprit') ? 402 : (str_contains($message, 'déjà membre') ? 409 : 404));
            return $this->json(['error' => $status === 403 ? 'Access denied.' : $message], $status, ['Cache-Control' => 'no-store, private']);
        } catch (\Throwable) {
            return $this->json(['error' => 'The member action could not be completed.'], 503, ['Cache-Control' => 'no-store, private']);
        }
    }
}
