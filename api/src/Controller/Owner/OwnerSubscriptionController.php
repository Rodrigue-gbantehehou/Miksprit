<?php

declare(strict_types=1);

namespace App\Controller\Owner;

use App\Entity\User;
use App\Service\Auth\CsrfCookieManager;
use App\Service\Owner\OwnerTenantContextUnavailable;
use App\Service\Owner\OwnerTenantSelectionRequired;
use App\Service\Owner\ResolveOwnerContext;
use App\Service\Provider\Payment\PaymentProviderUnavailable;
use App\Service\Subscription\PlatformSubscriptionService;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/owner/subscription', name: 'api_owner_subscription_')]
final class OwnerSubscriptionController extends AbstractController
{
    public function __construct(private readonly ResolveOwnerContext $contexts, private readonly PlatformSubscriptionService $subscriptions) {}

    #[Route('', name: 'summary', methods: ['GET'])]
    public function summary(Request $request): JsonResponse
    {
        $actor = $this->getUser();
        if (!$actor instanceof User) return $this->json(['error' => 'Authentication required.'], 401, ['Cache-Control' => 'no-store']);
        try {
            $context = $this->contexts->execute($actor->id(), $request->headers->get('X-Tenant-ID'));
            return $this->json(['subscription' => $this->subscriptions->ownerSummary($context)], 200, ['Cache-Control' => 'no-store, private']);
        } catch (OwnerTenantSelectionRequired) {
            return $this->json(['error' => 'Organization selection is required.'], 409, ['Cache-Control' => 'no-store, private']);
        } catch (OwnerTenantContextUnavailable) {
            return $this->json(['error' => 'An active organization is required.'], 403, ['Cache-Control' => 'no-store, private']);
        } catch (\DomainException $exception) {
            return $this->json(['error' => str_contains($exception->getMessage(), 'Access denied') ? 'Access denied.' : 'Tenant context not found.'], str_contains($exception->getMessage(), 'Access denied') ? 403 : 404, ['Cache-Control' => 'no-store, private']);
        }
    }

    #[Route('/checkout', name: 'checkout', methods: ['POST'])]
    public function checkout(Request $request, CsrfCookieManager $csrf): JsonResponse
    {
        $actor = $this->getUser();
        if (!$actor instanceof User) return $this->json(['error' => 'Authentication required.'], 401, ['Cache-Control' => 'no-store']);
        try { $data = $request->toArray(); } catch (\Throwable) { return $this->json(['error' => 'A valid JSON body is required.'], 400, ['Cache-Control' => 'no-store']); }
        if (!$csrf->isValid($request, $data)) return $this->json(['error' => 'Invalid CSRF token.'], 403, ['Cache-Control' => 'no-store']);
        try {
            $context = $this->contexts->execute($actor->id(), $request->headers->get('X-Tenant-ID'));
            $result = $this->subscriptions->startCheckout($context, is_string($data['offerId'] ?? null) ? $data['offerId'] : '');
            return $this->json($result, 200, ['Cache-Control' => 'no-store, private']);
        } catch (OwnerTenantSelectionRequired) {
            return $this->json(['error' => 'Organization selection is required.'], 409, ['Cache-Control' => 'no-store, private']);
        } catch (OwnerTenantContextUnavailable) {
            return $this->json(['error' => 'An active organization is required.'], 403, ['Cache-Control' => 'no-store, private']);
        } catch (\InvalidArgumentException $exception) {
            return $this->json(['error' => $exception->getMessage()], 422, ['Cache-Control' => 'no-store, private']);
        } catch (PaymentProviderUnavailable $exception) {
            return $this->json(['error' => $exception->getMessage()], 503, ['Cache-Control' => 'no-store, private']);
        } catch (UniqueConstraintViolationException) {
            return $this->json(['error' => 'Un paiement d’abonnement est déjà en cours. Rechargez la page.'], 409, ['Cache-Control' => 'no-store, private']);
        } catch (\DomainException $exception) {
            $message = $exception->getMessage();
            $status = str_contains($message, 'Access denied') ? 403 : (str_contains($message, 'Terminez') || str_contains($message, 'rapproché') ? 409 : 404);
            return $this->json(['error' => $status === 403 ? 'Access denied.' : $message], $status, ['Cache-Control' => 'no-store, private']);
        }
    }
}
