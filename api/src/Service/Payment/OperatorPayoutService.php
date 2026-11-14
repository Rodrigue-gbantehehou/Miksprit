<?php

declare(strict_types=1);

namespace App\Service\Payment;

use App\Entity\TenantContext;
use App\Service\Provider\Payment\FedaPayConfiguration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;

/** Tenant-scoped available balance and atomic payout reservation. */
final readonly class OperatorPayoutService
{
    public function __construct(private Connection $connection, private FedaPayConfiguration $configuration) {}

    /** @return array{payoutsEnabled:bool,balance:array{availableXof:int,reservedXof:int,paidXof:int},items:list<array<string,mixed>>} */
    public function overview(TenantContext $tenant): array
    {
        $wallet = (int) $this->connection->fetchOne('SELECT COALESCE(SUM(amount_delta_minor), 0) FROM operator_wallet_entries WHERE tenant_id = ?', [$tenant->tenantId->value]);
        $reserved = (int) $this->connection->fetchOne("SELECT COALESCE(SUM(amount_minor_units), 0) FROM operator_payout_requests WHERE tenant_id = ? AND state IN ('requested','processing','pending','ambiguous')", [$tenant->tenantId->value]);
        $paid = (int) $this->connection->fetchOne("SELECT COALESCE(SUM(amount_minor_units), 0) FROM operator_payout_requests WHERE tenant_id = ? AND state = 'sent'", [$tenant->tenantId->value]);
        $rows = $this->connection->fetchAllAssociative('SELECT id, amount_minor_units, mobile_money_provider, beneficiary_phone, state, created_at FROM operator_payout_requests WHERE tenant_id = ? ORDER BY created_at DESC LIMIT 100', [$tenant->tenantId->value]);
        return ['payoutsEnabled' => $this->configuration->canSendPayouts(), 'balance' => ['availableXof' => max(0, $wallet - $reserved), 'reservedXof' => $reserved, 'paidXof' => $paid], 'items' => array_map(static fn (array $row): array => [
            'id' => (string) $row['id'], 'amountXof' => (int) $row['amount_minor_units'],
            'provider' => (string) $row['mobile_money_provider'], 'phoneLast4' => substr((string) $row['beneficiary_phone'], -4),
            'status' => (string) $row['state'], 'createdAt' => (new \DateTimeImmutable((string) $row['created_at']))->format(DATE_ATOM),
        ], $rows)];
    }

    /** @return array{id:string,amountXof:int,status:string} */
    public function request(TenantContext $tenant, string $actorId, string $email, string $name, string $phone, int $amount, string $idempotencyKey): array
    {
        if (!$this->configuration->canSendPayouts()) {
            throw new \DomainException('Les reversements Mobile Money ne sont pas activés.');
        }
        $name = trim($name); $phone = trim($phone);
        if ($amount < 1 || $amount > 100_000_000 || mb_strlen($name) < 3 || mb_strlen($name) > 160
            || count(preg_split('/\\s+/', $name, -1, PREG_SPLIT_NO_EMPTY) ?: []) < 2
            || preg_match('/^\\+229[0-9]{8}$/D', $phone) !== 1
            || preg_match('/^[A-Za-z0-9_-]{16,128}$/D', $idempotencyKey) !== 1) {
            throw new \InvalidArgumentException('Montant ou coordonnées Mobile Money invalides.');
        }
        $hash = hash('sha256', $idempotencyKey);
        return $this->connection->transactional(function (Connection $connection) use ($tenant, $actorId, $email, $name, $phone, $amount, $hash): array {
            $existing = $connection->fetchAssociative('SELECT id, amount_minor_units, state, beneficiary_name, beneficiary_phone, mobile_money_provider FROM operator_payout_requests WHERE tenant_id = ? AND idempotency_key_hash = ?', [$tenant->tenantId->value, $hash]);
            if (is_array($existing)) {
                if ((int) $existing['amount_minor_units'] !== $amount
                    || !hash_equals((string) $existing['beneficiary_name'], $name)
                    || !hash_equals((string) $existing['beneficiary_phone'], $phone)) {
                    throw new \DomainException('Cette clé d’idempotence est déjà liée à une autre demande.');
                }
                return ['id' => (string) $existing['id'], 'amountXof' => (int) $existing['amount_minor_units'], 'status' => (string) $existing['state']];
            }
            $connection->fetchOne('SELECT tenant_id FROM organizations WHERE tenant_id = ?'.($connection->getDatabasePlatform() instanceof AbstractMySQLPlatform ? ' FOR UPDATE' : ''), [$tenant->tenantId->value]);
            $wallet = (int) $connection->fetchOne('SELECT COALESCE(SUM(amount_delta_minor), 0) FROM operator_wallet_entries WHERE tenant_id = ?', [$tenant->tenantId->value]);
            $reserved = (int) $connection->fetchOne("SELECT COALESCE(SUM(amount_minor_units), 0) FROM operator_payout_requests WHERE tenant_id = ? AND state IN ('requested','processing','pending','ambiguous')", [$tenant->tenantId->value]);
            if ($amount > max(0, $wallet - $reserved)) throw new \DomainException('Le solde disponible est insuffisant.');
            $id = 'payout_'.bin2hex(random_bytes(16)); $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
            $connection->insert('operator_payout_requests', ['id' => $id, 'tenant_id' => $tenant->tenantId->value,
                'requester_user_id' => $actorId, 'requester_email' => $email, 'amount_minor_units' => $amount,
                'currency' => 'XOF', 'beneficiary_name' => $name, 'beneficiary_phone' => $phone,
                'mobile_money_provider' => 'mobile_money', 'provider_reference' => null, 'state' => 'requested',
                'idempotency_key_hash' => $hash, 'last_error_code' => null, 'created_at' => $now, 'updated_at' => $now, 'sent_at' => null]);
            return ['id' => $id, 'amountXof' => $amount, 'status' => 'requested'];
        });
    }
}
