<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Stores the public checkout idempotency lookup and hosted URL for safe client retries. */
final class Version20261114171000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add checkout idempotency and hosted session URL to payment attempts.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE payment_attempts ADD checkout_url TEXT DEFAULT NULL, ADD checkout_request_key_hash VARCHAR(64) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_payment_checkout_request ON payment_attempts (checkout_request_key_hash)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_payment_checkout_request ON payment_attempts');
        $this->addSql('ALTER TABLE payment_attempts DROP checkout_url, DROP checkout_request_key_hash');
    }
}
