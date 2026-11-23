<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Auth\CompleteOperatorRegistration;
use App\Service\Auth\CsrfCookieManager;
use App\Service\Auth\CreateOperatorAccount;
use App\Service\Auth\OperatorRegistrationConflict;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\RateLimiter\RateLimiterFactory;

final readonly class OperatorRegistrationController
{
    public function __construct(
        #[Autowire(service: 'limiter.operator_registration_ip')] private RateLimiterFactory $ipLimiter,
        #[Autowire(service: 'limiter.operator_registration_email')] private RateLimiterFactory $emailLimiter,
        #[Autowire(service: 'limiter.operator_registration_verify')] private RateLimiterFactory $verificationLimiter,
    ) {
    }

    #[Route('/api/v1/public/operator-registrations', name: 'api_public_operator_registration', methods: ['POST'])]
    public function request(
        Request $request,
        CsrfCookieManager $csrf,
        CreateOperatorAccount $registration,
        LoggerInterface $logger,
    ): JsonResponse {
        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return new JsonResponse(['error' => 'invalid_request'], JsonResponse::HTTP_BAD_REQUEST, ['Cache-Control' => 'no-store']);
        }
        if (!$csrf->isValid($request, $data)) {
            return new JsonResponse(['error' => 'invalid_request'], JsonResponse::HTTP_BAD_REQUEST, ['Cache-Control' => 'no-store']);
        }
        $email = is_string($data['email'] ?? null) ? mb_strtolower(trim($data['email'])) : '';
        $ip = $request->getClientIp() ?? 'unknown';
        $limits = [
            $this->ipLimiter->create($ip)->consume(1),
            $this->emailLimiter->create(hash('sha256', $email))->consume(1),
        ];
        foreach ($limits as $limit) {
            if (!$limit->isAccepted()) {
                return new JsonResponse(['error' => 'too_many_requests'], JsonResponse::HTTP_TOO_MANY_REQUESTS, [
                    'Retry-After' => (string) max(1, $limit->getRetryAfter()->getTimestamp() - time()),
                    'Cache-Control' => 'no-store',
                ]);
            }
        }

        try {
            $account = $registration->execute(
                $email,
                is_string($data['password'] ?? null) ? $data['password'] : '',
            );
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => 'invalid_registration'], JsonResponse::HTTP_UNPROCESSABLE_ENTITY, ['Cache-Control' => 'no-store']);
        } catch (OperatorRegistrationConflict) {
            return new JsonResponse(['error' => 'email_or_organization_already_used'], JsonResponse::HTTP_CONFLICT, ['Cache-Control' => 'no-store']);
        } catch (\Throwable) {
            $logger->error('Operator account creation failed.');
            return new JsonResponse(['error' => 'registration_unavailable'], JsonResponse::HTTP_SERVICE_UNAVAILABLE, ['Cache-Control' => 'no-store']);
        }

        return new JsonResponse([
            'message' => 'Votre compte est créé. Connectez-vous pour créer votre espace Miksprit et vos sites.',
            ...$account,
        ], JsonResponse::HTTP_CREATED, ['Cache-Control' => 'no-store']);
    }

    #[Route('/api/v1/public/operator-registrations/verify', name: 'api_public_operator_registration_verify', methods: ['POST'])]
    public function verify(Request $request, CsrfCookieManager $csrf, CompleteOperatorRegistration $registration): JsonResponse
    {
        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return new JsonResponse(['error' => 'invalid_request'], JsonResponse::HTTP_BAD_REQUEST, ['Cache-Control' => 'no-store']);
        }
        if (!$csrf->isValid($request, $data)) {
            return new JsonResponse(['error' => 'invalid_request'], JsonResponse::HTTP_BAD_REQUEST, ['Cache-Control' => 'no-store']);
        }
        $limit = $this->verificationLimiter->create($request->getClientIp() ?? 'unknown')->consume(1);
        if (!$limit->isAccepted()) {
            return new JsonResponse(['error' => 'too_many_requests'], JsonResponse::HTTP_TOO_MANY_REQUESTS, [
                'Retry-After' => (string) max(1, $limit->getRetryAfter()->getTimestamp() - time()),
                'Cache-Control' => 'no-store',
            ]);
        }

        $token = is_string($data['token'] ?? null) ? $data['token'] : '';
        if (!$registration->complete($token)) {
            return new JsonResponse(['error' => 'registration_link_invalid'], JsonResponse::HTTP_BAD_REQUEST, ['Cache-Control' => 'no-store']);
        }
        return new JsonResponse(['message' => 'Adresse confirmée. Votre espace opérateur est prêt; vous pouvez vous connecter.'], JsonResponse::HTTP_OK, ['Cache-Control' => 'no-store']);
    }
}
