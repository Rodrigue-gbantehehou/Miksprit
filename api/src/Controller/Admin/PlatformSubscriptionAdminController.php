<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Service\Admin\AdminAuditLogger;
use App\Service\Auth\CsrfCookieManager;
use App\Service\Subscription\PlatformSubscriptionService;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/v1/admin')]
#[IsGranted('ROLE_SUPER_ADMIN')]
final class PlatformSubscriptionAdminController extends AbstractController
{
    public function __construct(private readonly PlatformSubscriptionService $subscriptions, private readonly AdminAuditLogger $audit) {}

    #[Route('/subscription-offers', methods: ['GET'])]
    public function offers(): JsonResponse
    {
        $this->record('admin.subscription_offers.read', 'platform_subscription_offers');
        return $this->json(['items' => $this->subscriptions->adminOffers()], 200, ['Cache-Control' => 'no-store, private']);
    }

    #[Route('/subscription-offers', methods: ['POST'])]
    public function createOffer(Request $request, CsrfCookieManager $csrf): JsonResponse
    {
        return $this->saveOffer(null, $request, $csrf);
    }

    #[Route('/subscription-offers/{id}', methods: ['PATCH'])]
    public function updateOffer(string $id, Request $request, CsrfCookieManager $csrf): JsonResponse
    {
        return $this->saveOffer($id, $request, $csrf);
    }

    #[Route('/subscriptions', methods: ['GET'])]
    public function subscriptions(): JsonResponse
    {
        $this->record('admin.subscriptions.read', 'platform_subscriptions');
        return $this->json(['items' => $this->subscriptions->adminSubscriptions()], 200, ['Cache-Control' => 'no-store, private']);
    }

    #[Route('/subscriptions/{id}/status', methods: ['PATCH'])]
    public function setStatus(string $id, Request $request, CsrfCookieManager $csrf): JsonResponse
    {
        try { $data = $request->toArray(); } catch (\Throwable) { return $this->json(['error' => 'A valid JSON body is required.'], 400); }
        if (!$csrf->isValid($request, $data)) return $this->json(['error' => 'Invalid CSRF token.'], 403);
        try {
            $status = is_string($data['status'] ?? null) ? $data['status'] : '';
            $this->subscriptions->setAdminStatus($id, $status);
            $this->record('admin.subscription.status_changed', $id);
            return $this->json(['id' => $id, 'status' => $status], 200, ['Cache-Control' => 'no-store, private']);
        } catch (\InvalidArgumentException $exception) {
            return $this->json(['error' => $exception->getMessage()], 422);
        } catch (\DomainException $exception) {
            return $this->json(['error' => $exception->getMessage()], 404);
        }
    }

    private function saveOffer(?string $id, Request $request, CsrfCookieManager $csrf): JsonResponse
    {
        try { $data = $request->toArray(); } catch (\Throwable) { return $this->json(['error' => 'A valid JSON body is required.'], 400); }
        if (!$csrf->isValid($request, $data)) return $this->json(['error' => 'Invalid CSRF token.'], 403);
        try {
            $offer = $this->subscriptions->saveOffer($id, $data);
            $this->record($id === null ? 'admin.subscription_offer.created' : 'admin.subscription_offer.updated', (string) $offer['id']);
            return $this->json(['offer' => $offer], $id === null ? 201 : 200, ['Cache-Control' => 'no-store, private']);
        } catch (\InvalidArgumentException $exception) {
            return $this->json(['error' => $exception->getMessage()], 422);
        } catch (UniqueConstraintViolationException) {
            return $this->json(['error' => 'Le code d’offre est déjà utilisé.'], 409);
        } catch (\DomainException $exception) {
            return $this->json(['error' => $exception->getMessage()], str_contains($exception->getMessage(), 'Configurez') ? 409 : 404);
        }
    }

    private function record(string $action, string $target): void
    {
        $actor = $this->getUser();
        if ($actor instanceof User) $this->audit->record($actor, $action, $target);
    }
}
