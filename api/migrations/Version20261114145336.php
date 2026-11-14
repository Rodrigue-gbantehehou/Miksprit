<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261114145336 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE access_plans (id VARCHAR(80) NOT NULL, site_id VARCHAR(80) NOT NULL, publication_state VARCHAR(16) NOT NULL, name VARCHAR(120) NOT NULL, session_seconds INT NOT NULL, data_limit_bytes INT DEFAULT NULL, device_limit SMALLINT NOT NULL, version INT NOT NULL, tenant_id VARCHAR(80) NOT NULL, price_minor_units INT NOT NULL, INDEX idx_plan_site_state (site_id, publication_state), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB');
        $this->addSql('CREATE TABLE account_action_tokens (id VARCHAR(36) NOT NULL, user_id VARCHAR(36) NOT NULL, token_hash VARCHAR(64) NOT NULL, purpose VARCHAR(24) NOT NULL, expires_at DATETIME NOT NULL, created_at DATETIME NOT NULL, consumed_at DATETIME DEFAULT NULL, INDEX idx_account_action_user_purpose (user_id, purpose), UNIQUE INDEX uniq_account_action_token_hash (token_hash), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB');
        $this->addSql('CREATE TABLE admin_audit_events (id VARCHAR(32) NOT NULL, actor_user_id VARCHAR(36) NOT NULL, action VARCHAR(80) NOT NULL, target VARCHAR(160) NOT NULL, created_at DATETIME NOT NULL, INDEX idx_admin_audit_created (created_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB');
        $this->addSql('CREATE TABLE hotspot_sessions (id VARCHAR(80) NOT NULL, router_id VARCHAR(80) NOT NULL, voucher_id VARCHAR(80) NOT NULL, external_session_id VARCHAR(160) NOT NULL, state VARCHAR(16) NOT NULL, started_at DATETIME DEFAULT NULL, ended_at DATETIME DEFAULT NULL, bytes_in BIGINT DEFAULT NULL, bytes_out BIGINT DEFAULT NULL, created_at DATETIME NOT NULL, tenant_id VARCHAR(80) NOT NULL, INDEX idx_session_tenant_state (tenant_id, state), UNIQUE INDEX uniq_router_external_session (router_id, external_session_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB');
        $this->addSql('CREATE TABLE ledger_entries (id VARCHAR(80) NOT NULL, journal_id VARCHAR(80) NOT NULL, entry_type VARCHAR(40) NOT NULL, debit_account VARCHAR(40) NOT NULL, credit_account VARCHAR(40) NOT NULL, amount_minor INT NOT NULL, currency VARCHAR(3) NOT NULL, source_type VARCHAR(40) NOT NULL, source_reference VARCHAR(200) NOT NULL, idempotency_key VARCHAR(200) NOT NULL, occurred_at DATETIME NOT NULL, recorded_at DATETIME NOT NULL, tenant_id VARCHAR(80) NOT NULL, INDEX idx_ledger_journal (tenant_id, journal_id), INDEX idx_ledger_occurred (tenant_id, occurred_at), UNIQUE INDEX uniq_ledger_tenant_idempotency (tenant_id, idempotency_key), UNIQUE INDEX uniq_ledger_source_posting (tenant_id, source_type, source_reference, entry_type), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB');
        $this->addSql('CREATE TABLE ledger_posting_idempotency (tenant_id VARCHAR(80) NOT NULL, idempotency_key VARCHAR(200) NOT NULL, fingerprint VARCHAR(64) NOT NULL, state VARCHAR(16) NOT NULL, entry_id VARCHAR(80) DEFAULT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (tenant_id, idempotency_key)) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB');
        $this->addSql('CREATE TABLE organizations (tenant_id VARCHAR(80) NOT NULL, name VARCHAR(160) NOT NULL, slug VARCHAR(80) NOT NULL, created_at DATETIME NOT NULL, organization_status VARCHAR(16) NOT NULL, UNIQUE INDEX uniq_organization_slug (slug), PRIMARY KEY (tenant_id)) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB');
        $this->addSql('CREATE TABLE payment_attempts (state VARCHAR(16) NOT NULL, provider_reference VARCHAR(200) DEFAULT NULL, id VARCHAR(80) NOT NULL, order_reference VARCHAR(200) NOT NULL, provider VARCHAR(40) NOT NULL, currency VARCHAR(3) NOT NULL, tenant_id VARCHAR(80) NOT NULL, expected_minor_units INT NOT NULL, UNIQUE INDEX uniq_provider_transaction (provider, provider_reference), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB');
        $this->addSql('CREATE TABLE payment_outbox (id VARCHAR(80) NOT NULL, order_reference VARCHAR(160) NOT NULL, message_type VARCHAR(32) NOT NULL, idempotency_key VARCHAR(200) NOT NULL, state VARCHAR(16) NOT NULL, created_at DATETIME NOT NULL, tenant_id VARCHAR(80) NOT NULL, INDEX idx_payment_outbox_pending (state, created_at), UNIQUE INDEX uniq_payment_outbox_tenant_idempotency (tenant_id, idempotency_key), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB');
        $this->addSql('CREATE TABLE payment_webhook_inbox (idempotency_key VARCHAR(80) NOT NULL, state VARCHAR(16) NOT NULL, claimed_at DATETIME NOT NULL, completed_at DATETIME DEFAULT NULL, PRIMARY KEY (idempotency_key)) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB');
        $this->addSql('CREATE TABLE routers (id VARCHAR(80) NOT NULL, site_id VARCHAR(80) NOT NULL, router_name VARCHAR(120) NOT NULL, host VARCHAR(253) NOT NULL, api_port SMALLINT NOT NULL, credential_reference VARCHAR(160) NOT NULL, router_os_version VARCHAR(32) DEFAULT NULL, status VARCHAR(24) NOT NULL, last_seen_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, tenant_id VARCHAR(80) NOT NULL, INDEX idx_router_tenant_status (tenant_id, status), UNIQUE INDEX uniq_router_tenant_site_name (tenant_id, site_id, router_name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB');
        $this->addSql('CREATE TABLE sites (id VARCHAR(80) NOT NULL, site_name VARCHAR(120) NOT NULL, slug VARCHAR(80) NOT NULL, public_token_hash VARCHAR(64) NOT NULL, status VARCHAR(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, tenant_id VARCHAR(80) NOT NULL, INDEX idx_site_tenant_status (tenant_id, status), UNIQUE INDEX uniq_site_tenant_slug (tenant_id, slug), UNIQUE INDEX uniq_site_public_token_hash (public_token_hash), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB');
        $this->addSql('CREATE TABLE tenant_memberships (id VARCHAR(160) NOT NULL, user_id VARCHAR(160) NOT NULL, role VARCHAR(16) NOT NULL, status VARCHAR(16) NOT NULL, created_at DATETIME NOT NULL, accepted_at DATETIME DEFAULT NULL, revoked_at DATETIME DEFAULT NULL, tenant_id VARCHAR(80) NOT NULL, UNIQUE INDEX uniq_tenant_member (tenant_id, user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB');
        $this->addSql('CREATE TABLE users (id VARCHAR(36) NOT NULL, email VARCHAR(190) NOT NULL, password_hash VARCHAR(255) NOT NULL, roles JSON NOT NULL, active TINYINT NOT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX uniq_user_email (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB');
        $this->addSql('CREATE TABLE voucher_provisioning_idempotency (idempotency_key VARCHAR(200) NOT NULL, state VARCHAR(16) NOT NULL, claimed_at DATETIME NOT NULL, completed_at DATETIME DEFAULT NULL, PRIMARY KEY (idempotency_key)) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB');
        $this->addSql('CREATE TABLE vouchers (id VARCHAR(80) NOT NULL, site_id VARCHAR(80) NOT NULL, order_reference VARCHAR(160) DEFAULT NULL, code_hash VARCHAR(64) NOT NULL, encrypted_credential LONGTEXT DEFAULT NULL, status VARCHAR(16) NOT NULL, expires_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, tenant_id VARCHAR(80) NOT NULL, INDEX idx_voucher_site_status (site_id, status), UNIQUE INDEX uniq_voucher_tenant_code_hash (tenant_id, code_hash), UNIQUE INDEX uniq_voucher_order (tenant_id, order_reference), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB');
        $this->addSql('CREATE TABLE wifi_orders (state VARCHAR(24) NOT NULL, voucher_reference VARCHAR(200) DEFAULT NULL, successful_payment_attempt_id VARCHAR(80) DEFAULT NULL, id VARCHAR(80) NOT NULL, public_reference VARCHAR(160) NOT NULL, created_at DATETIME NOT NULL, tenant_id VARCHAR(80) NOT NULL, plan_plan_id VARCHAR(80) NOT NULL, plan_site_id VARCHAR(80) NOT NULL, plan_plan_version INT NOT NULL, plan_plan_name VARCHAR(120) NOT NULL, plan_session_seconds INT NOT NULL, plan_data_limit_bytes BIGINT DEFAULT NULL, plan_device_limit SMALLINT NOT NULL, plan_plan_tenant_id VARCHAR(80) NOT NULL, plan_plan_price_minor_units INT NOT NULL, UNIQUE INDEX uniq_order_public_reference (public_reference), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE access_plans');
        $this->addSql('DROP TABLE account_action_tokens');
        $this->addSql('DROP TABLE admin_audit_events');
        $this->addSql('DROP TABLE hotspot_sessions');
        $this->addSql('DROP TABLE ledger_entries');
        $this->addSql('DROP TABLE ledger_posting_idempotency');
        $this->addSql('DROP TABLE organizations');
        $this->addSql('DROP TABLE payment_attempts');
        $this->addSql('DROP TABLE payment_outbox');
        $this->addSql('DROP TABLE payment_webhook_inbox');
        $this->addSql('DROP TABLE routers');
        $this->addSql('DROP TABLE sites');
        $this->addSql('DROP TABLE tenant_memberships');
        $this->addSql('DROP TABLE users');
        $this->addSql('DROP TABLE voucher_provisioning_idempotency');
        $this->addSql('DROP TABLE vouchers');
        $this->addSql('DROP TABLE wifi_orders');
    }
}
