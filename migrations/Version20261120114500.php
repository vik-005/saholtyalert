<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261120114500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crée le stockage persistant des clés WebAuthn des utilisateurs';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE webauthn_credential (
            id INT AUTO_INCREMENT NOT NULL,
            user_id INT NOT NULL,
            credential_id VARCHAR(255) NOT NULL,
            public_key VARCHAR(1000) NOT NULL,
            transports JSON NOT NULL,
            sign_count INT NOT NULL,
            aaguid VARCHAR(255) DEFAULT NULL,
            user_handle VARCHAR(255) NOT NULL,
            name VARCHAR(100) NOT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            last_used_at DATETIME DEFAULT NULL,
            UNIQUE INDEX uniq_webauthn_user_credential (user_id, credential_id),
            INDEX idx_webauthn_credential_id (credential_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE webauthn_credential
            ADD CONSTRAINT fk_webauthn_credential_user
            FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE webauthn_credential DROP FOREIGN KEY fk_webauthn_credential_user');
        $this->addSql('DROP TABLE webauthn_credential');
    }
}
