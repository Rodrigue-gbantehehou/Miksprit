<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261121170000 extends AbstractMigration
{
    public function getDescription(): string { return 'Add revocable rotating refresh-token sessions.'; }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE auth_refresh_sessions (id VARCHAR(32) NOT NULL, user_id VARCHAR(36) NOT NULL, absolute_expires_at DATETIME NOT NULL, revoked_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, INDEX idx_refresh_session_user (user_id), INDEX idx_refresh_session_expiry (absolute_expires_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB');
        $this->addSql('CREATE TABLE auth_refresh_tokens (token_hash CHAR(64) NOT NULL, session_id VARCHAR(32) NOT NULL, expires_at DATETIME NOT NULL, consumed_at DATETIME DEFAULT NULL, INDEX idx_refresh_token_session (session_id), INDEX idx_refresh_token_expiry (expires_at), PRIMARY KEY(token_hash)) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB');
        $this->addSql('ALTER TABLE auth_refresh_sessions ADD CONSTRAINT fk_refresh_session_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE auth_refresh_tokens ADD CONSTRAINT fk_refresh_token_session FOREIGN KEY (session_id) REFERENCES auth_refresh_sessions (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE auth_refresh_tokens');
        $this->addSql('DROP TABLE auth_refresh_sessions');
    }
}
