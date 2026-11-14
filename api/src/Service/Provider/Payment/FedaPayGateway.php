<?php

declare(strict_types=1);

namespace App\Service\Provider\Payment;

use App\Entity\PaymentState;
use App\Entity\VerifiedPaymentStatus;
use App\Entity\XofAmount;
use App\Event\PaymentWebhookEvent;
use App\Service\Payment\PaymentIntent;
use App\Service\Payment\PaymentProviderAdapter;
use App\Service\Payment\PaymentSession;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('miksprit.payment_provider')]
final readonly class FedaPayGateway implements PaymentProviderAdapter
{
    public function __construct(private FedaPayConfiguration $configuration, private JsonHttpClient $http, private WebhookSignatureValidator $signatures)
    {
    }

    public function code(): string { return 'fedapay'; }
    public function canCreateCheckout(): bool { return $this->configuration->canCreatePayments() && $this->configuration->canValidateWebhooks(); }

    public function createCheckout(PaymentIntent $intent, string $idempotencyKey): PaymentSession
    {
        $this->assertAvailable();
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 200) throw new \InvalidArgumentException('Payment idempotency key is invalid.');
        $payload = [
            'description' => $intent->description,
            'amount' => $intent->amountMinor,
            'currency' => ['iso' => $intent->currency],
            'callback_url' => $intent->returnUrl,
            'custom_metadata' => ['merchant_reference' => $intent->merchantReference, 'idempotency_key' => $idempotencyKey],
        ];
        if ($intent->customer !== null) {
            $customer = ['email' => $intent->customer->email];
            if ($intent->customer->firstName !== null) $customer['firstname'] = $intent->customer->firstName;
            if ($intent->customer->lastName !== null) $customer['lastname'] = $intent->customer->lastName;
            if ($intent->customer->phoneNumber !== null && $intent->customer->phoneCountry !== null) {
                $customer['phone_number'] = ['number' => (int) $intent->customer->phoneNumber, 'country' => $intent->customer->phoneCountry];
            }
            $payload['customer'] = $customer;
        }
        $transaction = self::resource($this->request('POST', '/transactions', $payload), 'transaction');
        $id = $transaction['id'] ?? null;
        if (!is_string($id) && !is_int($id)) throw new PaymentProviderUnavailable('FedaPay did not return a transaction identifier.');
        try {
            return $this->hostedSession((string) $id);
        } catch (PaymentProviderUnavailable $exception) {
            throw new CheckoutTokenUnavailable((string) $id, $exception);
        }
    }

    public function resumeCheckout(string $providerReference, string $idempotencyKey): PaymentSession
    {
        $this->assertAvailable();
        if ($providerReference === '' || strlen($providerReference) > 200 || $idempotencyKey === '' || strlen($idempotencyKey) > 200) {
            throw new \InvalidArgumentException('FedaPay checkout retry reference is invalid.');
        }
        return $this->hostedSession($providerReference);
    }

    private function hostedSession(string $providerReference): PaymentSession
    {
        $token = self::resource($this->request('POST', '/transactions/'.rawurlencode($providerReference).'/token'), 'token');
        $url = $token['url'] ?? $token['payment_url'] ?? null;
        $parts = is_string($url) ? parse_url($url) : false;
        $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || !isset($parts['host'])
            || ($host !== 'fedapay.com' && !str_ends_with($host, '.fedapay.com'))) {
            throw new PaymentProviderUnavailable('FedaPay did not return a valid hosted checkout URL.');
        }
        return new PaymentSession($providerReference, $url);
    }

    public function verify(string $providerReference): VerifiedPaymentStatus
    {
        $this->assertAvailable();
        if ($providerReference === '' || strlen($providerReference) > 200) throw new \InvalidArgumentException('FedaPay transaction reference is invalid.');
        $transaction = self::resource($this->request('GET', '/transactions/'.rawurlencode($providerReference)), 'transaction');
        $reference = $transaction['id'] ?? null;
        $merchantReference = $transaction['custom_metadata']['merchant_reference'] ?? $transaction['merchant_reference'] ?? null;
        $amount = $transaction['amount'] ?? null;
        $currency = $transaction['currency']['iso'] ?? $transaction['currency'] ?? null;
        $status = $transaction['status'] ?? null;
        $fee = $transaction['fees'] ?? null;
        $transferred = $transaction['amount_transferred'] ?? null;
        if ((!is_string($reference) && !is_int($reference)) || (!is_string($merchantReference) && !is_int($merchantReference))
            || !is_numeric($amount) || (int) $amount < 0 || !is_string($currency) || !is_string($status)) {
            throw new PaymentProviderUnavailable('FedaPay transaction response is incomplete.');
        }
        if (($fee !== null && (!is_numeric($fee) || (int) $fee < 0))
            || ($transferred !== null && (!is_numeric($transferred) || (int) $transferred < 0))) {
            throw new PaymentProviderUnavailable('FedaPay transaction settlement amounts are invalid.');
        }
        $isSettled = strtolower($status) === 'transferred' && $fee !== null && $transferred !== null;
        return new VerifiedPaymentStatus('fedapay', (string) $reference, (string) $merchantReference, new XofAmount((int) $amount), strtoupper($currency), self::mapStatus($status), new \DateTimeImmutable(), $fee === null ? null : (int) $fee, $transferred === null ? null : (int) $transferred, $isSettled);
    }

    public function validateWebhook(string $rawBody, ?string $signature): bool { return $this->signatures->isValid($rawBody, $signature); }
    public function webhookSignatureHeader(): string { return 'X-FEDAPAY-SIGNATURE'; }
    public function parseWebhook(string $rawBody): PaymentWebhookEvent { return FedaPayWebhookParser::parse($rawBody); }

    private function assertAvailable(): void
    {
        if (!$this->canCreateCheckout()) throw new PaymentProviderUnavailable('FedaPay checkout and webhook verification are not configured.');
    }

    /** Create a Mobile Money payout draft. Persist the returned ID before submitting it. */
    public function createPayoutDraft(string $name, string $email, int $amount): string
    {
        if (!$this->configuration->canSendPayouts()) throw new PaymentProviderUnavailable('FedaPay payouts are not enabled for this account.');
        if ($amount < 1) {
            throw new \InvalidArgumentException('FedaPay payout details are invalid.');
        }
        $parts = preg_split('/\\s+/', trim($name), 2) ?: [];
        if (count($parts) < 2) throw new \InvalidArgumentException('A beneficiary first and last name are required.');
        $response = $this->request('POST', '/payouts', [
            'amount' => $amount, 'currency' => ['iso' => 'XOF'], 'mode' => 'mobile_money',
            'customer' => ['firstname' => $parts[0], 'lastname' => $parts[1], 'email' => $email],
        ]);
        $payout = self::resource($response, 'payout');
        $id = $payout['id'] ?? null;
        if (!is_string($id) && !is_int($id)) throw new PaymentProviderUnavailable('FedaPay did not return a payout identifier.');
        return (string) $id;
    }

    public function submitPayout(string $providerReference, string $phone): void
    {
        if (!$this->configuration->canSendPayouts()) throw new PaymentProviderUnavailable('FedaPay payouts are not enabled for this account.');
        if (preg_match('/^\\+229[0-9]{8}$/D', $phone) !== 1 || preg_match('/^[0-9]+$/D', $providerReference) !== 1) {
            throw new \InvalidArgumentException('FedaPay payout reference or Benin phone number is invalid.');
        }
        $this->request('PUT', '/payouts/start', [[
            'id' => (int) $providerReference,
            'phone_number' => ['number' => $phone, 'country' => 'BJ'],
        ]]);
    }

    /** @return array{status:string,reference:string} */
    public function getPayout(string $providerReference): array
    {
        if (!$this->configuration->canSendPayouts()) throw new PaymentProviderUnavailable('FedaPay payouts are not enabled for this account.');
        $payload = self::resource($this->request('GET', '/payouts/'.rawurlencode($providerReference)), 'payout');
        $id = $payload['id'] ?? $providerReference;
        $status = $payload['status'] ?? null;
        if ((!is_string($id) && !is_int($id)) || !is_string($status)) throw new PaymentProviderUnavailable('FedaPay payout response is incomplete.');
        return ['status' => strtolower($status), 'reference' => (string) $id];
    }

    /** @param array<string, mixed>|null $payload @return array<string, mixed> */
    private function request(string $method, string $path, ?array $payload = null): array
    {
        $response = $this->http->request($method, rtrim((string) $this->configuration->baseUrl, '/').$path, [
            'Authorization' => 'Bearer '.(string) $this->configuration->secretKey,
            'Content-Type' => 'application/json',
        ], $payload);
        if ($response->statusCode < 200 || $response->statusCode >= 300) throw new PaymentProviderUnavailable('FedaPay rejected the payment operation (HTTP '.$response->statusCode.').');
        return $response->data;
    }

    /** @param array<string, mixed> $response @return array<string, mixed> */
    private static function resource(array $response, string $resource): array
    {
        $value = $response['v1'][$resource] ?? $response[$resource] ?? $response['data'] ?? $response;
        if (!is_array($value)) throw new PaymentProviderUnavailable('FedaPay response is missing its resource.');
        return $value;
    }

    private static function mapStatus(string $status): PaymentState
    {
        return match (strtolower($status)) {
            'approved', 'transferred', 'paid' => PaymentState::Succeeded,
            'declined', 'canceled', 'cancelled' => PaymentState::Failed,
            'pending', 'undone' => PaymentState::Pending,
            default => throw new PaymentProviderUnavailable('FedaPay transaction status is not supported.'),
        };
    }
}
