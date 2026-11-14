<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Membership;
use App\Entity\Organization;
use App\Entity\TenantId;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Admin\AdminAuditLogger;
use App\Service\Admin\AdminReadModel;
use App\Service\Auth\AccountLinkIssuer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Service\Auth\CsrfCookieManager;

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

    #[Route('/users', methods: ['POST'])]
    public function inviteOwner(
        Request $request,
        CsrfCookieManager $csrf,
        UserRepository $users,
        UserPasswordHasherInterface $passwordHasher,
        AccountLinkIssuer $accountLinks,
    ): JsonResponse {
        if (!$csrf->isValid($request, $this->jsonBody($request))) {
            return $this->json(['error' => 'Invalid CSRF token.'], 403, ['Cache-Control' => 'no-store']);
        }
        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->json(['error' => 'A valid JSON body is required.'], 400);
        }
        $email = is_string($data['email'] ?? null) ? mb_strtolower(trim($data['email'])) : '';
        $organizationName = is_string($data['organizationName'] ?? null) ? trim($data['organizationName']) : '';
        $slug = is_string($data['slug'] ?? null) ? strtolower(trim($data['slug'])) : '';
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 190
            || $organizationName === '' || mb_strlen($organizationName) > 160
            || preg_match('/^[a-z0-9](?:[a-z0-9-]{0,78}[a-z0-9])?$/D', $slug) !== 1) {
            return $this->json(['error' => 'Email, organizationName, or slug is invalid.'], 422);
        }
        if ($users->findByEmail($email) !== null || $this->entityManager->getRepository(Organization::class)->findOneBy(['slug' => $slug]) !== null) {
            return $this->json(['error' => 'The email or organization slug is already in use.'], 409);
        }

        $owner = User::create(bin2hex(random_bytes(16)), $email, 'pending');
        $owner->setPassword($passwordHasher->hashPassword($owner, bin2hex(random_bytes(32))));
        $tenantId = new TenantId(bin2hex(random_bytes(16)));
        $organization = Organization::create($tenantId, $organizationName, $slug);
        $membership = Membership::invite(bin2hex(random_bytes(16)), $tenantId, $owner->id(), \App\Entity\TenantRole::Owner);
        $this->entityManager->wrapInTransaction(function (EntityManagerInterface $entityManager) use ($owner, $organization, $membership, $users): void {
            $entityManager->persist($organization);
            $entityManager->persist($membership);
            $users->save($owner);
        });

        try {
            $accountLinks->sendInvitation($owner);
        } catch (\Throwable) {
            $this->record('admin.owner.invitation_email_failed', $tenantId->value);
            return $this->json(['error' => 'The owner and organization were created, but the invitation could not be sent. Retry delivery from account management.'], 503);
        }
        $this->record('admin.owner.invited', $tenantId->value);

        return $this->json(['userId' => $owner->id(), 'tenantId' => $tenantId->value, 'email' => $email, 'membershipStatus' => 'invited'], 201, ['Cache-Control' => 'no-store, private']);
    }

    #[Route('/users/{id}/status', methods: ['PATCH'])]
    public function setUserStatus(string $id, Request $request, CsrfCookieManager $csrf): JsonResponse
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
        $this->entityManager->flush();
        $this->record($data['active'] ? 'admin.user.activated' : 'admin.user.deactivated', $user->id());
        return $this->json(['id' => $user->id(), 'email' => $user->email(), 'active' => $user->isActive()], 200, ['Cache-Control' => 'no-store, private']);
    }

    #[Route('/users/{id}/invitation', methods: ['POST'])]
    public function resendInvitation(string $id, Request $request, CsrfCookieManager $csrf, AccountLinkIssuer $accountLinks): JsonResponse
    {
        if (!$csrf->isValid($request, $this->jsonBody($request))) {
            return $this->json(['error' => 'Invalid CSRF token.'], 403, ['Cache-Control' => 'no-store']);
        }
        $user = $this->entityManager->getRepository(User::class)->find($id);
        if (!$user instanceof User) {
            return $this->json(['error' => 'User not found.'], 404);
        }
        $hasPendingInvitation = (int) $this->entityManager->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM tenant_memberships WHERE user_id = ? AND status = 'invited'",
            [$user->id()],
        ) > 0;
        if (!$hasPendingInvitation) {
            return $this->json(['error' => 'This account has no pending organization invitation.'], 409);
        }
        try {
            $accountLinks->sendInvitation($user);
        } catch (\Throwable) {
            $this->record('admin.user.invitation_resend_failed', $user->id());
            return $this->json(['error' => 'The invitation could not be sent.'], 503);
        }
        $this->record('admin.user.invitation_resent', $user->id());
        return $this->json(['id' => $user->id(), 'status' => 'sent'], 202, ['Cache-Control' => 'no-store, private']);
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
