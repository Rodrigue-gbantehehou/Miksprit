<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Adds a settled operator balance and on-demand Mobile Money payouts. */
final class Version20261114182000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record a frozen sale commission, operator payable ledger, and FedaPay payout requests.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE pending_operator_registrations (
            id VARCHAR(36) NOT NULL,
            email VARCHAR(190) NOT NULL,
            organization_name VARCHAR(160) NOT NULL,
            organization_slug VARCHAR(80) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            token_hash CHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL,
            consumed_at DATETIME DEFAULT NULL,
            UNIQUE INDEX uniq_pending_operator_registration_token (token_hash),
            INDEX idx_pending_operator_registration_expiry (expires_at),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB");
        $this->addSql('ALTER TABLE wifi_orders ADD platform_commission_bps SMALLINT DEFAULT NULL');
        $this->addSql("CREATE TABLE operator_settlements (
            id VARCHAR(80) NOT NULL,
            tenant_id VARCHAR(80) NOT NULL,
            order_reference VARCHAR(160) NOT NULL,
            gross_minor_units INT NOT NULL,
            provider_fee_minor_units INT DEFAULT NULL,
            amount_transferred_minor_units INT DEFAULT NULL,
            commission_bps SMALLINT DEFAULT NULL,
            commission_minor_units INT DEFAULT NULL,
            operator_net_minor_units INT DEFAULT NULL,
            state VARCHAR(24) NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE INDEX uniq_operator_settlement_order (tenant_id, order_reference),
            INDEX idx_operator_settlement_available (tenant_id, state),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB");
        $this->addSql("CREATE TABLE operator_wallet_entries (
            id VARCHAR(80) NOT NULL,
            tenant_id VARCHAR(80) NOT NULL,
            source_type VARCHAR(32) NOT NULL,
            source_reference VARCHAR(200) NOT NULL,
            source_reference_hash CHAR(64) NOT NULL,
            entry_type VARCHAR(24) NOT NULL,
            amount_delta_minor INT NOT NULL,
            currency CHAR(3) NOT NULL,
            created_at DATETIME NOT NULL,
            UNIQUE INDEX uniq_operator_wallet_source (tenant_id, source_type, source_reference_hash, entry_type),
            INDEX idx_operator_wallet_balance (tenant_id, id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB");
        $this->addSql("CREATE TABLE operator_payout_requests (
            id VARCHAR(80) NOT NULL,
            tenant_id VARCHAR(80) NOT NULL,
            requester_user_id VARCHAR(160) NOT NULL,
            requester_email VARCHAR(190) NOT NULL,
            amount_minor_units INT NOT NULL,
            currency CHAR(3) NOT NULL,
            beneficiary_name VARCHAR(160) NOT NULL,
            beneficiary_phone VARCHAR(20) NOT NULL,
            mobile_money_provider VARCHAR(24) NOT NULL,
            provider_reference VARCHAR(80) DEFAULT NULL,
            state VARCHAR(24) NOT NULL,
            idempotency_key_hash CHAR(64) NOT NULL,
            last_error_code VARCHAR(64) DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            sent_at DATETIME DEFAULT NULL,
            UNIQUE INDEX uniq_operator_payout_idempotency (tenant_id, idempotency_key_hash),
            UNIQUE INDEX uniq_operator_payout_provider_ref (provider_reference),
            INDEX idx_operator_payout_work (state, created_at),
            INDEX idx_operator_payout_tenant_state (tenant_id, state),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE operator_payout_requests');
        $this->addSql('DROP TABLE operator_wallet_entries');
        $this->addSql('DROP TABLE operator_settlements');
        $this->addSql('ALTER TABLE wifi_orders DROP platform_commission_bps');
        $this->addSql('DROP TABLE pending_operator_registrations');
    }
}
