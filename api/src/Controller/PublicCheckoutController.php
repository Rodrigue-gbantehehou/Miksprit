<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Payment\CreatePublicWifiCheckout;
use App\Service\Payment\PaymentCustomer;
use App\Service\Payment\PublicPaymentUnavailable;
use App\Service\Payment\PublicCheckoutInProgress;
use App\Service\Provider\Payment\PaymentProviderUnavailable;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

final readonly class PublicCheckoutController
{
    public function __construct(
        private CreatePublicWifiCheckout $checkout,
        #[Autowire(service: 'limiter.public_checkout')] private RateLimiterFactory $limiter,
    ) {
    }

    #[Route('/api/v1/public/sites/{siteId}/checkout', name: 'api_public_checkout_start', methods: ['POST'])]
    public function start(string $siteId, Request $request): JsonResponse
    {
        $limit = $this->limiter->create(($request->getClientIp() ?? 'unknown').':'.$siteId)->consume(1);
        if (!$limit->isAccepted()) {
            return new JsonResponse(['error' => 'rate_limited'], JsonResponse::HTTP_TOO_MANY_REQUESTS,
                ['Retry-After' => (string) max(1, $limit->getRetryAfter()->getTimestamp() - time()), 'Cache-Control' => 'no-store']);
        }
        try {
            $data = $request->toArray();
            $customer = new PaymentCustomer(
                email: is_string($data['email'] ?? null) ? trim($data['email']) : '',
                firstName: self::optionalString($data['firstName'] ?? null),
                lastName: self::optionalString($data['lastName'] ?? null),
                phoneNumber: self::optionalString($data['phoneNumber'] ?? null),
                phoneCountry: self::optionalString($data['phoneCountry'] ?? null),
            );
            $planId = is_string($data['planId'] ?? null) ? $data['planId'] : '';
            $idempotencyKey = $request->headers->get('Idempotency-Key', '');
            $result = $this->checkout->execute($siteId, $planId, $customer, $idempotencyKey);
        } catch (\InvalidArgumentException|\Symfony\Component\HttpFoundation\Exception\JsonException $exception) {
            return new JsonResponse(['error' => 'invalid_request'], JsonResponse::HTTP_BAD_REQUEST, ['Cache-Control' => 'no-store']);
        } catch (\DomainException $exception) {
            return new JsonResponse(['error' => 'plan_not_available'], JsonResponse::HTTP_NOT_FOUND, ['Cache-Control' => 'no-store']);
        } catch (PublicCheckoutInProgress $exception) {
            return new JsonResponse(['error' => 'checkout_in_progress', 'orderReference' => $exception->orderReference], JsonResponse::HTTP_CONFLICT, ['Cache-Control' => 'no-store']);
        } catch (PaymentProviderUnavailable|PublicPaymentUnavailable|\App\Service\Payment\UnknownPaymentProvider) {
            return new JsonResponse(['error' => 'payment_temporarily_unavailable'], JsonResponse::HTTP_SERVICE_UNAVAILABLE, ['Cache-Control' => 'no-store']);
        } catch (\Throwable) {
            return new JsonResponse(['error' => 'checkout_unavailable'], JsonResponse::HTTP_SERVICE_UNAVAILABLE, ['Cache-Control' => 'no-store']);
        }

        return new JsonResponse([
            'orderReference' => $result->orderReference,
            'provider' => $result->provider,
            'checkoutUrl' => $result->session->checkoutUrl,
        ], JsonResponse::HTTP_CREATED, ['Cache-Control' => 'no-store']);
    }

    private static function optionalString(mixed $value): ?string
    {
        if ($value === null) return null;
        return is_string($value) ? trim($value) : '';
    }
}
