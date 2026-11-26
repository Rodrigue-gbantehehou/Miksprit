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

    public function sendEmailVerification(User $user): void
    {
        if ($user->emailVerifiedAt() !== null) return;
        $this->issue($user, AccountLinkPurpose::EmailVerification);
    }

    public function sendInvitation(User $user): void
    {
        $this->issue($user, AccountLinkPurpose::Invitation);
    }

    public function invalidateInvitationLinks(User $user): void
    {
        $this->tokens->invalidateOutstanding($user->id(), AccountLinkPurpose::Invitation, new \DateTimeImmutable());
    }

    private function issue(User $user, AccountLinkPurpose $purpose): void
    {
        $now = new \DateTimeImmutable();
        $rawToken = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $ttl = match ($purpose) {
            AccountLinkPurpose::Invitation => new \DateInterval('P3D'),
            AccountLinkPurpose::EmailVerification => new \DateInterval('P1D'),
            AccountLinkPurpose::PasswordReset => new \DateInterval('PT1H'),
        };
        $this->tokens->invalidateOutstanding($user->id(), $purpose, $now);
        $this->tokens->save(AccountActionToken::issue(
            bin2hex(random_bytes(16)),
            $user->id(),
            hash('sha256', $rawToken),
            $purpose,
            $now->add($ttl),
        ));

        if ($purpose === AccountLinkPurpose::EmailVerification) {
            $url = $this->baseUrl.'/verifier-compte?'.http_build_query(['token' => $rawToken], '', '&', PHP_QUERY_RFC3986);
            $subject = 'Confirmez votre adresse e-mail Miksprit';
            $expiry = '24 heures';
            $instruction = 'Votre compte est déjà utilisable. Confirmez votre adresse à l’aide de ce lien sécurisé';
        } else {
            $url = $this->baseUrl.'/reinitialiser?'.http_build_query(['purpose' => $purpose->value, 'token' => $rawToken], '', '&', PHP_QUERY_RFC3986);
            $subject = $purpose === AccountLinkPurpose::Invitation ? 'Votre accès Miksprit' : 'Réinitialisation de votre mot de passe Miksprit';
            $expiry = $purpose === AccountLinkPurpose::Invitation ? '3 jours' : '1 heure';
            $instruction = 'Pour continuer, ouvrez ce lien sécurisé';
        }
        $this->email->send($user->email(), $subject, "{$instruction} (valable {$expiry}) :\n\n{$url}\n\nSi vous n'avez pas demandé cette action, ignorez ce message.");
    }
}
