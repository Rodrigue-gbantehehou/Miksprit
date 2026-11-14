<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Entity\AccountActionToken;
use App\Entity\AccountLinkPurpose;
use App\Entity\User;
use App\Repository\AccountActionTokenRepository;

final readonly class AccountLinkIssuer
{
    private string $baseUrl;

    public function __construct(private AccountActionTokenRepository $tokens, private AuthEmailSender $email, string $publicBaseUrl)
    {
        $parts = parse_url($publicBaseUrl);
        if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new \InvalidArgumentException('AUTH_PUBLIC_BASE_URL must be an absolute HTTP(S) URL without credentials.');
        }
        $this->baseUrl = rtrim($publicBaseUrl, '/');
    }

    public function sendPasswordReset(User $user): void
    {
        $this->issue($user, AccountLinkPurpose::PasswordReset);
    }

    public function sendInvitation(User $user): void
    {
        $this->issue($user, AccountLinkPurpose::Invitation);
    }

    private function issue(User $user, AccountLinkPurpose $purpose): void
    {
        $now = new \DateTimeImmutable();
        $rawToken = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $ttl = $purpose === AccountLinkPurpose::Invitation ? new \DateInterval('P3D') : new \DateInterval('PT1H');
        $this->tokens->invalidateOutstanding($user->id(), $purpose, $now);
        $this->tokens->save(AccountActionToken::issue(
            bin2hex(random_bytes(16)),
            $user->id(),
            hash('sha256', $rawToken),
            $purpose,
            $now->add($ttl),
        ));

        $url = $this->baseUrl.'/reinitialiser?'.http_build_query(['purpose' => $purpose->value, 'token' => $rawToken], '', '&', PHP_QUERY_RFC3986);
        $subject = $purpose === AccountLinkPurpose::Invitation ? 'Votre accès Miksprit' : 'Réinitialisation de votre mot de passe Miksprit';
        $expiry = $purpose === AccountLinkPurpose::Invitation ? '3 jours' : '1 heure';
        $this->email->send($user->email(), $subject, "Pour continuer, ouvrez ce lien sécurisé (valable {$expiry}) :\n\n{$url}\n\nSi vous n'avez pas demandé cette action, ignorez ce message.");
    }
}
