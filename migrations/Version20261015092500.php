<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261015092500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add per-user Web Push subscriptions for device notifications';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE push_subscription (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, endpoint LONGTEXT NOT NULL, endpoint_hash VARCHAR(64) NOT NULL, public_key VARCHAR(255) NOT NULL, auth_token VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX uniq_push_subscription_endpoint_hash (endpoint_hash), INDEX IDX_PUSH_SUBSCRIPTION_USER (user_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE push_subscription ADD CONSTRAINT FK_PUSH_SUBSCRIPTION_USER FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE push_subscription DROP FOREIGN KEY FK_PUSH_SUBSCRIPTION_USER');
        $this->addSql('DROP TABLE push_subscription');
    }
}
