<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261023181959 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajout du compteur de requalifications pour limiter à 3 fois les modifications Manager (PFT), illimité pour SUPERADMIN et SAHOLTY';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE alert ADD requalification_count INT NOT NULL, CHANGE legal_status legal_status VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE password_reset_token DROP FOREIGN KEY `FK_PASSWORD_RESET_USER`');
        $this->addSql('ALTER TABLE password_reset_token CHANGE expires_at expires_at DATETIME NOT NULL, CHANGE created_at created_at DATETIME NOT NULL');
        $this->addSql('DROP INDEX uniq_password_reset_token_hash ON password_reset_token');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_6B7BA4B6B3BC57DA ON password_reset_token (token_hash)');
        $this->addSql('DROP INDEX idx_password_reset_user ON password_reset_token');
        $this->addSql('CREATE INDEX IDX_6B7BA4B6A76ED395 ON password_reset_token (user_id)');
        $this->addSql('ALTER TABLE password_reset_token ADD CONSTRAINT `FK_PASSWORD_RESET_USER` FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE push_subscription DROP FOREIGN KEY `FK_PUSH_SUBSCRIPTION_USER`');
        $this->addSql('ALTER TABLE push_subscription CHANGE created_at created_at DATETIME NOT NULL');
        $this->addSql('DROP INDEX idx_push_subscription_user ON push_subscription');
        $this->addSql('CREATE INDEX IDX_562830F3A76ED395 ON push_subscription (user_id)');
        $this->addSql('ALTER TABLE push_subscription ADD CONSTRAINT `FK_PUSH_SUBSCRIPTION_USER` FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE alert DROP requalification_count, CHANGE legal_status legal_status VARCHAR(10) DEFAULT NULL');
        $this->addSql('ALTER TABLE password_reset_token DROP FOREIGN KEY FK_6B7BA4B6A76ED395');
        $this->addSql('ALTER TABLE password_reset_token CHANGE expires_at expires_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('DROP INDEX idx_6b7ba4b6a76ed395 ON password_reset_token');
        $this->addSql('CREATE INDEX IDX_PASSWORD_RESET_USER ON password_reset_token (user_id)');
        $this->addSql('DROP INDEX uniq_6b7ba4b6b3bc57da ON password_reset_token');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_PASSWORD_RESET_TOKEN_HASH ON password_reset_token (token_hash)');
        $this->addSql('ALTER TABLE password_reset_token ADD CONSTRAINT FK_6B7BA4B6A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE push_subscription DROP FOREIGN KEY FK_562830F3A76ED395');
        $this->addSql('ALTER TABLE push_subscription CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('DROP INDEX idx_562830f3a76ed395 ON push_subscription');
        $this->addSql('CREATE INDEX IDX_PUSH_SUBSCRIPTION_USER ON push_subscription (user_id)');
        $this->addSql('ALTER TABLE push_subscription ADD CONSTRAINT FK_562830F3A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
    }
}
