<?php

declare(strict_types=1);

namespace App\Service\Auth;

use Lexik\Bundle\JWTAuthenticationBundle\Encoder\JWTEncoderInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class CsrfCookieManager
{
    public const COOKIE_NAME = 'MIKSPRIT_CSRF';

    public function __construct(
        private JWTEncoderInterface $encoder,
        #[Autowire('%kernel.environment%')] private string $environment,
        #[Autowire('%env(default:auth_cookie_domain:AUTH_COOKIE_DOMAIN)%')] private ?string $cookieDomain,
    ) {}

    public function issue(): string
    {
        return $this->encoder->encode([
            'csrf' => bin2hex(random_bytes(32)),
            'purpose' => 'miksprit_csrf',
            'iat' => time(),
            'exp' => time() + 3600,
        ]);
    }

    /** @param array<string, mixed> $data */
    public function isValid(Request $request, array $data): bool
    {
        $provided = $data['_csrf_token'] ?? $request->headers->get('X-CSRF-Token');
        $cookie = $request->cookies->get(self::COOKIE_NAME);
        if (!is_string($provided) || !is_string($cookie) || $provided === '' || !hash_equals($cookie, $provided)) {
            return false;
        }
        try {
            $claims = $this->encoder->decode($cookie);
        } catch (\Throwable) {
            return false;
        }

        return ($claims['purpose'] ?? null) === 'miksprit_csrf'
            && is_string($claims['csrf'] ?? null)
            && time() <= (int) ($claims['exp'] ?? 0);
    }

    public function attach(Response $response, string $token, Request $request): void
    {
        $response->headers->setCookie(Cookie::create(self::COOKIE_NAME)
            ->withValue($token)
            ->withExpires(time() + 3600)
            ->withPath('/')
            ->withDomain($this->cookieDomain)
            ->withSecure($request->isSecure() || $this->environment === 'prod')
            ->withHttpOnly(true)
            ->withSameSite(Cookie::SAMESITE_LAX));
    }
}
