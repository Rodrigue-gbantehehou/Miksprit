<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261120140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add configurable Miksprit SaaS subscription offers, tenant subscriptions, and independent payment history.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE platform_subscription_offers (id VARCHAR(80) NOT NULL, code VARCHAR(80) NOT NULL, name VARCHAR(120) NOT NULL, provider VARCHAR(40) NOT NULL, amount_minor_units INT NOT NULL, interval_days INT NOT NULL, grace_days SMALLINT NOT NULL DEFAULT 0, expired_access_policy VARCHAR(24) NOT NULL, active TINYINT(1) NOT NULL DEFAULT 0, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX uniq_subscription_offer_code (code), INDEX idx_subscription_offer_active (active, amount_minor_units), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB');
        $this->addSql('CREATE TABLE platform_subscriptions (id VARCHAR(80) NOT NULL, tenant_id VARCHAR(80) NOT NULL, offer_id VARCHAR(80) NOT NULL, status VARCHAR(16) NOT NULL, offer_name_snapshot VARCHAR(120) NOT NULL, amount_minor_units_snapshot INT NOT NULL, interval_days_snapshot INT NOT NULL, grace_days_snapshot SMALLINT NOT NULL DEFAULT 0, expired_access_policy VARCHAR(24) NOT NULL, started_at DATETIME DEFAULT NULL, current_period_start DATETIME DEFAULT NULL, current_period_end DATETIME DEFAULT NULL, next_due_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX uniq_platform_subscription_tenant (tenant_id), INDEX idx_platform_subscription_status_due (status, next_due_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB');
        $this->addSql('CREATE TABLE platform_subscription_payments (id VARCHAR(80) NOT NULL, tenant_id VARCHAR(80) NOT NULL, subscription_id VARCHAR(80) NOT NULL, offer_id VARCHAR(80) NOT NULL, provider VARCHAR(40) NOT NULL, merchant_reference VARCHAR(200) NOT NULL, provider_reference VARCHAR(200) DEFAULT NULL, offer_name_snapshot VARCHAR(120) NOT NULL, amount_minor_units INT NOT NULL, interval_days INT NOT NULL, grace_days SMALLINT NOT NULL DEFAULT 0, expired_access_policy VARCHAR(24) NOT NULL, currency VARCHAR(3) NOT NULL, state VARCHAR(20) NOT NULL, open_key VARCHAR(80) DEFAULT NULL, checkout_url VARCHAR(2048) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, paid_at DATETIME DEFAULT NULL, UNIQUE INDEX uniq_subscription_payment_merchant_ref (merchant_reference), UNIQUE INDEX uniq_subscription_payment_provider_ref (provider, provider_reference), UNIQUE INDEX uniq_subscription_payment_open (open_key), INDEX idx_subscription_payment_tenant_history (tenant_id, created_at), INDEX idx_subscription_payment_pending (tenant_id, state), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE platform_subscription_payments');
        $this->addSql('DROP TABLE platform_subscriptions');
        $this->addSql('DROP TABLE platform_subscription_offers');
    }
}
