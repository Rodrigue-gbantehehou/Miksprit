<?php

declare(strict_types=1);

use App\Entity\Membership;
use App\Entity\PaymentState;
use App\Entity\TenantContext;
use App\Entity\TenantId;
use App\Entity\User;
use App\Entity\VerifiedPaymentStatus;
use App\Entity\XofAmount;
use App\Repository\DoctrinePaymentIdempotencyStore;
use App\Repository\UserRepository;
use App\Service\Payment\PaymentProviderRegistry;
use App\Service\Provider\Payment\FedaPayConfiguration;
use App\Service\Provider\Payment\FedaPayGateway;
use App\Service\Provider\Payment\JsonHttpClient;
use App\Service\Provider\Payment\JsonHttpResponse;
use App\Service\Provider\Payment\WebhookSignatureValidator;
use App\Service\Payment\TenantPaymentSettings;
use App\Service\Subscription\PlatformSubscriptionService;
use App\Service\Subscription\PlatformSubscriptionWebhookService;
use App\Service\Subscription\TenantSubscriptionAccess;
use Doctrine\DBAL\DriverManager;

require dirname(__DIR__, 2).'/vendor/autoload.php';

final class PlatformSubscriptionFakeHttpClient implements JsonHttpClient
{
    public ?string $merchantReference = null;

    public function request(string $method, string $url, array $headers = [], ?array $json = null): JsonHttpResponse
    {
        if ($method === 'POST' && str_ends_with($url, '/transactions')) {
            $this->merchantReference = (string) ($json['custom_metadata']['merchant_reference'] ?? '');
            return new JsonHttpResponse(201, ['transaction' => ['id' => 'platform_tx_001']]);
        }
        if ($method === 'POST' && str_ends_with($url, '/transactions/platform_tx_001/token')) {
            return new JsonHttpResponse(200, ['token' => ['url' => 'https://checkout.fedapay.com/session/platform-subscription']]);
        }
        if ($method === 'GET' && str_ends_with($url, '/transactions/platform_tx_001')) {
            return new JsonHttpResponse(200, ['transaction' => [
                'id' => 'platform_tx_001', 'custom_metadata' => ['merchant_reference' => $this->merchantReference],
                'amount' => 12500, 'currency' => ['iso' => 'XOF'], 'status' => 'approved',
            ]]);
        }
        throw new RuntimeException('Unexpected fake PSP request.');
    }
}

final class PlatformSubscriptionFakeUsers implements UserRepository
{
    public function findByEmail(string $email): ?User { return null; }
    public function findById(string $id): ?User { return $id === 'owner_subscription_01' ? User::create($id, 'owner@example.test', 'not-a-login-secret') : null; }
    public function save(User $user): void {}
}

$connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
$connection->executeStatement('CREATE TABLE tenant_payment_settings (tenant_id VARCHAR(80) PRIMARY KEY, provider VARCHAR(40) NOT NULL, environment VARCHAR(12) NOT NULL, encrypted_credentials LONGTEXT NOT NULL, last_api_check_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)');
$connection->executeStatement('CREATE TABLE payment_attempts (id VARCHAR(80) PRIMARY KEY, tenant_id VARCHAR(80) NOT NULL, provider VARCHAR(40) NOT NULL, provider_reference VARCHAR(200), state VARCHAR(16) NOT NULL, UNIQUE (tenant_id, provider, provider_reference))');
$connection->executeStatement('CREATE TABLE payment_webhook_inbox (idempotency_key VARCHAR(80) NOT NULL PRIMARY KEY, state VARCHAR(16) NOT NULL, claimed_at DATETIME NOT NULL, completed_at DATETIME DEFAULT NULL)');
$connection->executeStatement('CREATE TABLE platform_subscription_offers (id VARCHAR(80) PRIMARY KEY, code VARCHAR(80) NOT NULL UNIQUE, name VARCHAR(120) NOT NULL, provider VARCHAR(40) NOT NULL, amount_minor_units INT NOT NULL, interval_days INT NOT NULL, grace_days INT NOT NULL, expired_access_policy VARCHAR(24) NOT NULL, active BOOLEAN NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)');
$connection->executeStatement('CREATE TABLE platform_subscriptions (id VARCHAR(80) PRIMARY KEY, tenant_id VARCHAR(80) NOT NULL UNIQUE, offer_id VARCHAR(80) NOT NULL, status VARCHAR(16) NOT NULL, offer_name_snapshot VARCHAR(120) NOT NULL, amount_minor_units_snapshot INT NOT NULL, interval_days_snapshot INT NOT NULL, grace_days_snapshot INT NOT NULL, expired_access_policy VARCHAR(24) NOT NULL, started_at DATETIME, current_period_start DATETIME, current_period_end DATETIME, next_due_at DATETIME, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)');
$connection->executeStatement('CREATE TABLE platform_subscription_payments (id VARCHAR(80) PRIMARY KEY, tenant_id VARCHAR(80) NOT NULL, subscription_id VARCHAR(80) NOT NULL, offer_id VARCHAR(80) NOT NULL, provider VARCHAR(40) NOT NULL, merchant_reference VARCHAR(200) NOT NULL UNIQUE, provider_reference VARCHAR(200), offer_name_snapshot VARCHAR(120) NOT NULL, amount_minor_units INT NOT NULL, interval_days INT NOT NULL, grace_days INT NOT NULL, expired_access_policy VARCHAR(24) NOT NULL, currency VARCHAR(3) NOT NULL, state VARCHAR(20) NOT NULL, open_key VARCHAR(80) UNIQUE, checkout_url VARCHAR(2048), created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, paid_at DATETIME, UNIQUE (provider, provider_reference))');
$now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
$connection->insert('platform_subscription_offers', ['id' => 'offer_starter_001', 'code' => 'starter', 'name' => 'Offre configurée', 'provider' => 'fedapay', 'amount_minor_units' => 12500, 'interval_days' => 30, 'grace_days' => 0, 'expired_access_policy' => 'read_only', 'active' => 1, 'created_at' => $now, 'updated_at' => $now]);

$encryptionKey = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
$settings = new TenantPaymentSettings($connection, $encryptionKey);
$globalConfig = new FedaPayConfiguration(true, 'platform-secret-test', 'platform-webhook-test', 'https://sandbox-api.fedapay.com/v1');
$http = new PlatformSubscriptionFakeHttpClient();
$gateway = new FedaPayGateway($globalConfig, $http, new WebhookSignatureValidator($globalConfig), $settings);
$providers = new PaymentProviderRegistry([$gateway], $settings);
$tenantId = new TenantId('tenant_subscription_01');
$context = TenantContext::fromMembership(Membership::owner('membership_subscription_01', $tenantId, 'owner_subscription_01'), 'owner_subscription_01');
$service = new PlatformSubscriptionService($connection, new PlatformSubscriptionFakeUsers(), $providers, 'fedapay', 'https://portal.example.test/abonnement');
$checkout = $service->startCheckout($context, 'offer_starter_001');
if ($checkout['checkoutUrl'] !== 'https://checkout.fedapay.com/session/platform-subscription' || $http->merchantReference === null) {
    throw new RuntimeException('Subscription checkout was not created with the central merchant account.');
}
if ((int) $connection->fetchOne('SELECT COUNT(*) FROM payment_attempts') !== 0) {
    throw new RuntimeException('A subscription payment was mixed into the tenant ticket payment table.');
}

$webhook = new PlatformSubscriptionWebhookService($providers, new DoctrinePaymentIdempotencyStore($connection), $service);
$body = json_encode(['id' => 'subscription_event_01', 'type' => 'transaction.approved', 'object_id' => 'platform_tx_001'], JSON_THROW_ON_ERROR);
$timestamp = time();
$signature = 't='.$timestamp.',s='.hash_hmac('sha256', $timestamp.'.'.$body, 'platform-webhook-test');
$webhook->process('fedapay', $body, $signature);
$active = $service->ownerSummary($context)['subscription'];
if ($active['status'] !== 'active' || $active['amountXof'] !== 12500 || $active['intervalDays'] !== 30
    || (int) $connection->fetchOne("SELECT COUNT(*) FROM platform_subscription_payments WHERE state = 'succeeded' AND open_key IS NULL") !== 1) {
    throw new RuntimeException('Verified platform subscription payment did not activate an independent subscription.');
}
$end = $active['currentPeriodEnd'];
$webhook->process('fedapay', $body, $signature);
if ($service->ownerSummary($context)['subscription']['currentPeriodEnd'] !== $end) {
    throw new RuntimeException('A duplicate subscription webhook extended the subscription period twice.');
}

try {
    $webhook->process('fedapay', $body, 't='.$timestamp.',s='.str_repeat('0', 64));
    throw new RuntimeException('An invalid platform webhook signature was accepted.');
} catch (UnexpectedValueException $exception) {
    if ($exception->getMessage() === 'An invalid platform webhook signature was accepted.') throw $exception;
}

$access = new TenantSubscriptionAccess($connection);
if (!$access->mayOperate($tenantId) || !$access->mayOperate(new TenantId('tenant_without_subscription'))) {
    throw new RuntimeException('Active or not-yet-enrolled tenant access policy was incorrect.');
}
$connection->update('platform_subscriptions', ['current_period_end' => '2020-01-01 00:00:00'], ['tenant_id' => $tenantId->value]);
if ($access->mayOperate($tenantId) || $service->refreshExpiredSubscriptions() !== 1
    || $service->ownerSummary($context)['subscription']['status'] !== 'expired') {
    throw new RuntimeException('Expired subscription did not retain read access while blocking operations.');
}

echo "Independent subscription checkout, webhook, idempotency, and expiry proof passed.\n";
