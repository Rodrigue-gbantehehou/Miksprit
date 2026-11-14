<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\Auth\CsrfCookieManager;
use App\Service\Auth\AuthPayload;
use App\Service\Auth\AccountLinkIssuer;
use App\Service\Auth\CompleteAccountLink;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Security\Http\Event\LogoutEvent;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Psr\Log\LoggerInterface;

final class AuthController extends AbstractController
{
    public function __construct(
        #[Autowire('%kernel.environment%')] private readonly string $environment,
        #[Autowire('%env(default:auth_cookie_domain:AUTH_COOKIE_DOMAIN)%')] private readonly ?string $cookieDomain,
    ) {}

    #[Route('/api/v1/auth/csrf', name: 'api_auth_csrf', methods: ['GET'])]
    public function csrf(Request $request, CsrfCookieManager $csrf): JsonResponse
    {
        $token = $csrf->issue();
        $response = new JsonResponse([
            'csrfToken' => $token,
            'logoutCsrfToken' => $token,
            'adminWriteCsrfToken' => $token,
        ], 200, ['Cache-Control' => 'no-store, private']);
        $csrf->attach($response, $token, $request);
        return $response;
    }

    #[Route('/api/v1/auth/login', name: 'api_auth_login', methods: ['POST'])]
    public function login(
        Request $request,
        CsrfCookieManager $csrf,
        UserRepository $users,
        UserPasswordHasherInterface $passwordHasher,
        AuthPayload $payload,
        JWTTokenManagerInterface $jwt,
        #[Autowire(service: 'limiter.auth_login')] RateLimiterFactory $limiter,
    ): JsonResponse {
        $data = $this->jsonBody($request);
        if (!$csrf->isValid($request, $data)) {
            return new JsonResponse(['error' => 'Invalid request.'], 400, ['Cache-Control' => 'no-store']);
        }
        $key = hash('sha256', ($request->getClientIp() ?? 'unknown').'|'.mb_strtolower((string) ($data['email'] ?? '')));
        $limit = $limiter->create($key)->consume(1);
        if (!$limit->isAccepted()) {
            return new JsonResponse(['error' => 'Too many login attempts.'], 429, ['Retry-After' => (string) max(1, $limit->getRetryAfter()->getTimestamp() - time()), 'Cache-Control' => 'no-store']);
        }
        $email = is_string($data['email'] ?? null) ? mb_strtolower(trim($data['email'])) : '';
        $password = is_string($data['password'] ?? null) ? $data['password'] : '';
        $user = strlen($email) <= 190 ? $users->findByEmail($email) : null;
        if (!$user instanceof User || !$user->isActive() || strlen($password) > 4096 || !$passwordHasher->isPasswordValid($user, $password)) {
            return new JsonResponse(['error' => 'Invalid email or password.'], 401, ['Cache-Control' => 'no-store']);
        }
        $token = $jwt->create($user);
        $response = new JsonResponse($payload->for($user), 200, ['Cache-Control' => 'no-store, private']);
        $response->headers->setCookie(Cookie::create('MIKSPRIT_ACCESS')->withValue($token)->withExpires(time() + 900)->withPath('/')
            ->withDomain($this->cookieDomain)->withSecure($request->isSecure() || $this->environment === 'prod')->withHttpOnly(true)->withSameSite(Cookie::SAMESITE_LAX));
        return $response;
    }

    #[Route('/api/v1/auth/logout', name: 'api_auth_logout', methods: ['POST'])]
    public function logout(Request $request, CsrfCookieManager $csrf, EventDispatcherInterface $dispatcher, TokenStorageInterface $tokens): JsonResponse
    {
        $data = $this->jsonBody($request);
        if (!$csrf->isValid($request, $data)) {
            return new JsonResponse(['error' => 'Invalid request.'], 400, ['Cache-Control' => 'no-store']);
        }
        $dispatcher->dispatch(new LogoutEvent($request, $tokens->getToken()));
        $response = new JsonResponse(null, 204, ['Cache-Control' => 'no-store']);
        foreach (['MIKSPRIT_ACCESS', CsrfCookieManager::COOKIE_NAME] as $name) {
            $response->headers->clearCookie($name, '/', $this->cookieDomain, $request->isSecure() || $this->environment === 'prod', true, Cookie::SAMESITE_LAX);
        }
        return $response;
    }

    #[Route('/api/v1/auth/me', name: 'api_auth_me', methods: ['GET'])]
    public function me(AuthPayload $payload): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Authentication required.'], 401, ['Cache-Control' => 'no-store']);
        }
        return new JsonResponse($payload->for($user), 200, ['Cache-Control' => 'no-store, private']);
    }

    #[Route('/api/v1/auth/password/request', name: 'api_auth_password_request', methods: ['POST'])]
    public function requestPasswordLink(
        Request $request,
        CsrfCookieManager $csrf,
        UserRepository $users,
        AccountLinkIssuer $links,
        LoggerInterface $logger,
        #[Autowire(service: 'limiter.password_link_request')] RateLimiterFactory $limiter,
    ): JsonResponse {
        $data = $this->jsonBody($request);
        if (!$csrf->isValid($request, $data)) {
            return new JsonResponse(['error' => 'Invalid request.'], 400, ['Cache-Control' => 'no-store']);
        }
        $email = is_string($data['email'] ?? null) ? mb_strtolower(trim($data['email'])) : '';
        $key = hash('sha256', $request->getClientIp() ?? 'unknown');
        $limit = $limiter->create($key)->consume(1);
        if (!$limit->isAccepted()) {
            return new JsonResponse(['error' => 'Please wait before requesting another link.'], 429, [
                'Retry-After' => (string) max(1, $limit->getRetryAfter()->getTimestamp() - time()),
                'Cache-Control' => 'no-store',
            ]);
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) !== false && strlen($email) <= 190) {
            $user = $users->findByEmail($email);
            if ($user !== null && $user->isActive()) {
                try {
                    $links->sendPasswordReset($user);
                } catch (\Throwable) {
                    // Keep the response identical for existing and unknown addresses.
                    $logger->error('Password link email delivery failed.');
                }
            }
        }
        return new JsonResponse(['message' => 'If the account exists, a secure link has been sent.'], 202, ['Cache-Control' => 'no-store']);
    }

    #[Route('/api/v1/auth/password/reset', name: 'api_auth_password_reset', methods: ['POST'])]
    public function resetPassword(Request $request, CsrfCookieManager $csrf, CompleteAccountLink $complete): JsonResponse
    {
        $data = $this->jsonBody($request);
        if (!$csrf->isValid($request, $data)) {
            return new JsonResponse(['error' => 'Invalid request.'], 400, ['Cache-Control' => 'no-store']);
        }
        $token = is_string($data['token'] ?? null) ? $data['token'] : '';
        $purpose = is_string($data['purpose'] ?? null) ? $data['purpose'] : '';
        $password = is_string($data['password'] ?? null) ? $data['password'] : '';
        if (strlen($password) < 12 || strlen($password) > 4096) {
            return new JsonResponse(['error' => 'Le mot de passe doit contenir au moins 12 caractères.'], 422, ['Cache-Control' => 'no-store']);
        }
        if (!$complete->complete($token, $purpose, $password)) {
            return new JsonResponse(['error' => 'Ce lien est invalide ou expiré. Demandez-en un nouveau.'], 400, ['Cache-Control' => 'no-store']);
        }
        return new JsonResponse(['message' => 'Mot de passe mis à jour.'], 200, ['Cache-Control' => 'no-store']);
    }

    /** @return array<string, mixed> */
    private function jsonBody(Request $request): array
    {
        try {
            $data = $request->toArray();
            return is_array($data) ? $data : [];
        } catch (\Throwable) {
            return [];
        }
    }

}
