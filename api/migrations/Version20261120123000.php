<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Provider transaction IDs are scoped to merchant accounts, so uniqueness is tenant-scoped. */
final class Version20261120123000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Scope payment provider transaction references to their tenant.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_provider_transaction ON payment_attempts');
        $this->addSql('CREATE UNIQUE INDEX uniq_tenant_provider_transaction ON payment_attempts (tenant_id, provider, provider_reference)');
    }

    public function down(Schema $schema): void
    {
        $duplicates = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM payment_attempts a INNER JOIN payment_attempts b '
            .'ON a.provider = b.provider AND a.provider_reference = b.provider_reference AND a.id < b.id '
            .'WHERE a.provider_reference IS NOT NULL',
        );
        $this->abortIf($duplicates > 0, 'Cannot restore global provider-reference uniqueness because duplicate references exist across tenants.');
        $this->addSql('DROP INDEX uniq_tenant_provider_transaction ON payment_attempts');
        $this->addSql('CREATE UNIQUE INDEX uniq_provider_transaction ON payment_attempts (provider, provider_reference)');
    }
}
