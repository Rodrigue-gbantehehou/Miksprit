<?php

declare(strict_types=1);

namespace App\Service\Auth;

final readonly class OperatorRegistrationVerificationEmail
{
    private string $baseUrl;

    public function __construct(private AuthEmailSender $email, string $publicBaseUrl)
    {
        $parts = parse_url($publicBaseUrl);
        if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new \InvalidArgumentException('AUTH_PUBLIC_BASE_URL must be an absolute HTTP(S) URL without credentials.');
        }
        $this->baseUrl = rtrim($publicBaseUrl, '/');
    }

    public function send(string $email, string $rawToken): void
    {
        $url = $this->baseUrl.'/verifier-compte?'.http_build_query(['token' => $rawToken], '', '&', PHP_QUERY_RFC3986);
        $this->email->send($email, 'Confirmez votre compte opérateur Miksprit',
            "Pour confirmer votre adresse et créer votre espace opérateur Miksprit, ouvrez ce lien dans votre navigateur (valable une heure) :\n\n{$url}\n\nSi vous n'avez pas demandé cette inscription, ignorez ce message.");
    }
}
