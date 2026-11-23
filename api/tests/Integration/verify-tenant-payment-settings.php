<?php

declare(strict_types=1);

use App\Entity\Membership;
use App\Entity\PaymentState;
use App\Entity\TenantContext;
use App\Entity\TenantId;
use App\Service\Payment\PaymentCustomer;
use App\Service\Payment\PaymentIntent;
use App\Service\Payment\TenantPaymentSettings;
use App\Service\Provider\Payment\FedaPayConfiguration;
use App\Service\Provider\Payment\FedaPayGateway;
use App\Service\Provider\Payment\JsonHttpClient;
use App\Service\Provider\Payment\JsonHttpResponse;
use App\Service\Provider\Payment\WebhookSignatureValidator;
use Doctrine\DBAL\DriverManager;

require dirname(__DIR__, 2).'/vendor/autoload.php';

final class RecordingFedaPayHttpClient implements JsonHttpClient
{
    /** @var list<array{method:string,url:string,headers:array<string,string>}> */
    public array $requests = [];

    public function request(string $method, string $url, array $headers = [], ?array $json = null): JsonHttpResponse
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers];
        if (str_ends_with($url, '/transactions/search')) return new JsonHttpResponse(200, ['data' => []]);
        if (str_ends_with($url, '/transactions')) return new JsonHttpResponse(201, ['transaction' => ['id' => 'shared_tx_1']]);
        if (str_ends_with($url, '/token')) return new JsonHttpResponse(200, ['token' => ['url' => 'https://checkout.fedapay.com/session/abc']]);
        return new JsonHttpResponse(200, ['transaction' => [
            'id' => 'shared_tx_1', 'custom_metadata' => ['merchant_reference' => 'order_a'], 'amount' => 2500,
            'currency' => ['iso' => 'XOF'], 'status' => 'approved',
        ]]);
    }
}

$connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
$connection->executeStatement('CREATE TABLE tenant_payment_settings (tenant_id VARCHAR(80) PRIMARY KEY, provider VARCHAR(40) NOT NULL, environment VARCHAR(12) NOT NULL, encrypted_credentials LONGTEXT NOT NULL, last_api_check_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)');
$connection->executeStatement('CREATE TABLE payment_attempts (id VARCHAR(80) PRIMARY KEY, tenant_id VARCHAR(80) NOT NULL, provider VARCHAR(40) NOT NULL, provider_reference VARCHAR(200), state VARCHAR(16) NOT NULL, UNIQUE (tenant_id, provider, provider_reference))');

$key = '0123456789abcdef0123456789abcdef';
$settings = new TenantPaymentSettings($connection, $key);
$tenantA = TenantContext::fromMembership(Membership::owner('membership_a', new TenantId('tenant_a'), 'owner_a'), 'owner_a');
$tenantB = TenantContext::fromMembership(Membership::owner('membership_b', new TenantId('tenant_b'), 'owner_b'), 'owner_b');
$credentialsA = ['apiKey' => 'sk_sandbox_a', 'webhookSecret' => 'wh_sandbox_a', 'environment' => 'test'];
$credentialsB = ['apiKey' => 'sk_sandbox_b', 'webhookSecret' => 'wh_sandbox_b', 'environment' => 'test'];
$settings->save($tenantA, 'fedapay', 'test', $credentialsA['apiKey'], $credentialsA['webhookSecret']);
$settings->save($tenantB, 'fedapay', 'test', $credentialsB['apiKey'], $credentialsB['webhookSecret']);

if (isset($settings->read($tenantA)['apiKey']) || $settings->forTenant('tenant_a')['apiKey'] !== $credentialsA['apiKey']) {
    throw new RuntimeException('Tenant credentials were returned by the safe read or failed to decrypt.');
}
$base64Settings = new TenantPaymentSettings($connection, 'base64:'.base64_encode($key));
if ($base64Settings->forTenant('tenant_a')['apiKey'] !== $credentialsA['apiKey']) {
    throw new RuntimeException('Base64-encoded encryption key did not decrypt stored credentials.');
}

$connection->insert('payment_attempts', ['id' => 'attempt_a', 'tenant_id' => 'tenant_a', 'provider' => 'fedapay', 'provider_reference' => 'shared_tx_1', 'state' => PaymentState::Pending->value]);
$connection->insert('payment_attempts', ['id' => 'attempt_b', 'tenant_id' => 'tenant_b', 'provider' => 'fedapay', 'provider_reference' => 'shared_tx_1', 'state' => PaymentState::Pending->value]);
$candidates = $settings->forPaymentReference('fedapay', 'shared_tx_1');
if (count($candidates) !== 2) throw new RuntimeException('Provider references were not isolated by tenant.');

$http = new RecordingFedaPayHttpClient();
$gateway = new FedaPayGateway(
    new FedaPayConfiguration(enabled: true, baseUrl: 'https://sandbox-api.fedapay.com/v1'),
    $http,
    new WebhookSignatureValidator(new FedaPayConfiguration(enabled: true, webhookSecret: 'legacy-webhook')),
    $settings,
);
$gateway->testTenantCredentials($credentialsA);
$session = $gateway->createTenantCheckout(
    new PaymentIntent('order_a', 2500, 'XOF', 'Wi-Fi 1 heure', 'https://portal.example.test/return', new PaymentCustomer('buyer@example.test')),
    'attempt-a',
    $credentialsA,
);
if ($session->providerReference !== 'shared_tx_1' || $http->requests[0]['headers']['Authorization'] !== 'Bearer '.$credentialsA['apiKey']) {
    throw new RuntimeException('Tenant checkout did not use its own FedaPay API key.');
}

$body = '{"id":"event_1","type":"transaction.approved","object_id":"shared_tx_1"}';
$timestamp = time();
$signature = 't='.$timestamp.',s='.hash_hmac('sha256', $timestamp.'.'.$body, $credentialsA['webhookSecret']);
$event = $gateway->parseTenantWebhook($body, $signature);
if ($event->tenantId !== 'tenant_a') throw new RuntimeException('Webhook signature did not resolve the correct tenant.');
$verified = $gateway->verifyTenantPayment('shared_tx_1', $event->tenantId);
if ($verified->tenantId !== 'tenant_a' || $http->requests[array_key_last($http->requests)]['headers']['Authorization'] !== 'Bearer '.$credentialsA['apiKey']) {
    throw new RuntimeException('Provider verification did not stay on the webhook tenant account.');
}
$signatureB = 't='.$timestamp.',s='.hash_hmac('sha256', $timestamp.'.'.$body, $credentialsB['webhookSecret']);
$eventB = $gateway->parseTenantWebhook($body, $signatureB);
$gateway->verifyTenantPayment('shared_tx_1', $eventB->tenantId);
if ($eventB->tenantId !== 'tenant_b' || $http->requests[array_key_last($http->requests)]['headers']['Authorization'] !== 'Bearer '.$credentialsB['apiKey']) {
    throw new RuntimeException('A second merchant webhook did not resolve to its isolated account.');
}

try {
    $settings->save($tenantA, 'fedapay', 'test', 'sk_rotated', 'wh_rotated');
    throw new RuntimeException('Tenant keys were replaced while a payment was still pending.');
} catch (DomainException $exception) {
    if (!str_contains($exception->getMessage(), 'paiements sont encore')) throw $exception;
}
try {
    $settings->revoke($tenantA);
    throw new RuntimeException('Tenant keys were revoked while a payment was still pending.');
} catch (DomainException $exception) {
    if (!str_contains($exception->getMessage(), 'paiements sont encore')) throw $exception;
}
$connection->executeStatement("UPDATE payment_attempts SET state = 'succeeded' WHERE tenant_id = 'tenant_a'");
$settings->revoke($tenantA);
if ($settings->forTenant('tenant_a') !== null || $settings->forTenant('tenant_b')['apiKey'] !== $credentialsB['apiKey']) {
    throw new RuntimeException('Revocation affected another tenant or retained the revoked key.');
}

echo "Tenant payment credential, checkout, webhook, and revocation proof passed.\n";
