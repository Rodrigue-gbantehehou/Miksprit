<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Service\Admin\AdminAuditLogger;
use App\Service\Admin\AdminReadModel;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Service\Auth\CsrfCookieManager;
use App\Service\Auth\RefreshSessionStore;

#[Route('/api/v1/admin')]
#[IsGranted('ROLE_SUPER_ADMIN')]
final class AdminController extends AbstractController
{
    public function __construct(
        private readonly AdminReadModel $readModel,
        private readonly AdminAuditLogger $audit,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    #[Route('/summary', methods: ['GET'])]
    public function summary(): JsonResponse
    {
        $this->record('admin.read.summary', 'platform');
        return $this->json($this->readModel->summary(), 200, ['Cache-Control' => 'no-store, private']);
    }

    #[Route('/tenants', methods: ['GET'])]
    public function tenants(): JsonResponse
    {
        $this->record('admin.read.tenants', 'organizations');
        return $this->json($this->readModel->tenants(), 200, ['Cache-Control' => 'no-store, private']);
    }

    #[Route('/routers', methods: ['GET'])]
    public function routers(): JsonResponse
    {
        $this->record('admin.read.routers', 'routers');
        return $this->json($this->readModel->routers(), 200, ['Cache-Control' => 'no-store, private']);
    }

    #[Route('/payments', methods: ['GET'])]
    public function payments(): JsonResponse
    {
        $this->record('admin.read.payments', 'payment_attempts');
        return $this->json($this->readModel->payments(), 200, ['Cache-Control' => 'no-store, private']);
    }

    #[Route('/ledger', methods: ['GET'])]
    public function ledger(): JsonResponse
    {
        $this->record('admin.read.ledger', 'ledger_entries');
        return $this->json($this->readModel->ledger(), 200, ['Cache-Control' => 'no-store, private']);
    }

    #[Route('/audit', methods: ['GET'])]
    public function audit(): JsonResponse
    {
        $this->record('admin.read.audit', 'admin_audit_events');
        return $this->json($this->readModel->audit(), 200, ['Cache-Control' => 'no-store, private']);
    }

    #[Route('/operations', methods: ['GET'])]
    public function operations(): JsonResponse
    {
        $this->record('admin.read.operations', 'operations');
        return $this->json($this->readModel->operations(), 200, ['Cache-Control' => 'no-store, private']);
    }

    #[Route('/users', methods: ['GET'])]
    public function users(): JsonResponse
    {
        $this->record('admin.read.users', 'users');
        return $this->json($this->readModel->users(), 200, ['Cache-Control' => 'no-store, private']);
    }

    #[Route('/users/{id}/status', methods: ['PATCH'])]
    public function setUserStatus(string $id, Request $request, CsrfCookieManager $csrf, RefreshSessionStore $sessions): JsonResponse
    {
        if (!$csrf->isValid($request, $this->jsonBody($request))) {
            return $this->json(['error' => 'Invalid CSRF token.'], 403, ['Cache-Control' => 'no-store']);
        }
        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->json(['error' => 'A valid JSON body is required.'], 400);
        }
        if (!is_bool($data['active'] ?? null)) {
            return $this->json(['error' => 'The active field must be a boolean.'], 422);
        }
        $user = $this->entityManager->getRepository(User::class)->find($id);
        if (!$user instanceof User) {
            return $this->json(['error' => 'User not found.'], 404);
        }
        $actor = $this->getUser();
        if (!$data['active'] && $actor instanceof User && $actor->id() === $user->id()) {
            return $this->json(['error' => 'You cannot deactivate your own account.'], 422);
        }
        if (!$data['active'] && $user->isSuperAdmin()) {
            $activeAdmins = array_filter(
                $this->entityManager->getRepository(User::class)->findBy(['active' => true]),
                static fn (User $candidate): bool => $candidate->isSuperAdmin(),
            );
            if (count($activeAdmins) <= 1) {
                return $this->json(['error' => 'At least one active super-administrator must remain.'], 409);
            }
        }
        $user->setActive($data['active']);
        if (!$data['active']) $sessions->revokeUser($user->id());
        $this->entityManager->flush();
        $this->record($data['active'] ? 'admin.user.activated' : 'admin.user.deactivated', $user->id());
        return $this->json(['id' => $user->id(), 'email' => $user->email(), 'active' => $user->isActive()], 200, ['Cache-Control' => 'no-store, private']);
    }

    /** @return array<string, mixed> */
    private function jsonBody(Request $request): array
    {
        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return [];
        }
        return is_array($data) ? $data : [];
    }

    private function record(string $action, string $target): void
    {
        $actor = $this->getUser();
        if ($actor instanceof User) {
            $this->audit->record($actor, $action, $target);
        }
    }
}
