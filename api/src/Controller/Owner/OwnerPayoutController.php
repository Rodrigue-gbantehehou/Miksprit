<?php

declare(strict_types=1);

namespace App\Controller\Owner;

use App\Entity\TenantPermission;
use App\Entity\User;
use App\Service\Auth\CsrfCookieManager;
use App\Service\Owner\ResolveOwnerContext;
use App\Service\Payment\OperatorPayoutService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/owner/payouts', name: 'api_owner_payouts_')]
final class OwnerPayoutController extends AbstractController
{
    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request, ResolveOwnerContext $contexts, OperatorPayoutService $payouts): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) return $this->json(['error' => 'Authentication required.'], 401);
        try {
            $context = $contexts->execute($user->id(), $request->headers->get('X-Tenant-ID'));
            if (!$context->allows(TenantPermission::ManagePayouts)) return $this->json(['error' => 'Access denied.'], 403);
            return $this->json($payouts->overview($context), 200, ['Cache-Control' => 'no-store, private']);
        } catch (\Throwable) { return $this->json(['error' => 'Tenant context unavailable.'], 403); }
    }

    #[Route('', name: 'request', methods: ['POST'])]
    public function requestPayout(Request $request, CsrfCookieManager $csrf, ResolveOwnerContext $contexts, OperatorPayoutService $payouts): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) return $this->json(['error' => 'Authentication required.'], 401);
        try { $data = $request->toArray(); } catch (\Throwable) { return $this->json(['error' => 'A valid JSON body is required.'], 400); }
        if (!$csrf->isValid($request, $data)) return $this->json(['error' => 'Invalid CSRF token.'], 403, ['Cache-Control' => 'no-store']);
        try {
            $context = $contexts->execute($user->id(), $request->headers->get('X-Tenant-ID'));
            if (!$context->allows(TenantPermission::ManagePayouts)) return $this->json(['error' => 'Access denied.'], 403);
            $result = $payouts->request($context, $user->id(), $user->email(),
                is_string($data['beneficiaryName'] ?? null) ? $data['beneficiaryName'] : '',
                is_string($data['phoneNumber'] ?? null) ? $data['phoneNumber'] : '',
                is_int($data['amountXof'] ?? null) ? $data['amountXof'] : 0,
                $request->headers->get('Idempotency-Key', ''));
            return $this->json($result, 202, ['Cache-Control' => 'no-store, private']);
        } catch (\InvalidArgumentException $exception) {
            return $this->json(['error' => $exception->getMessage()], 422, ['Cache-Control' => 'no-store']);
        } catch (\DomainException $exception) {
            return $this->json(['error' => $exception->getMessage()], 409, ['Cache-Control' => 'no-store']);
        } catch (\Throwable) { return $this->json(['error' => 'Tenant context unavailable.'], 403, ['Cache-Control' => 'no-store']); }
    }
}
