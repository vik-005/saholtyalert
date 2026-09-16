<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Create import_batch and activity_log tables.
 */
final class Version20260915110327 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create import_batch and activity_log tables for import tracking and activity logging';
    }

    public function up(Schema $schema): void
    {
        // import_batch table
        $this->addSql('CREATE TABLE import_batch (
            id INT AUTO_INCREMENT NOT NULL,
            batch_id VARCHAR(64) NOT NULL,
            user_id INT NOT NULL,
            filename VARCHAR(255) NOT NULL,
            status VARCHAR(20) NOT NULL,
            lines_total INT NOT NULL DEFAULT 0,
            lines_success INT NOT NULL DEFAULT 0,
            lines_error INT NOT NULL DEFAULT 0,
            lines_updated INT NOT NULL DEFAULT 0,
            summary JSON DEFAULT NULL,
            errors JSON DEFAULT NULL,
            created_at DATETIME_IMMUTABLE NOT NULL,
            updated_at DATETIME_IMMUTABLE DEFAULT NULL,
            INDEX idx_import_status (status),
            INDEX idx_import_created (created_at),
            UNIQUE INDEX UNIQ_40C2E7D82E144876 (batch_id),
            INDEX IDX_40C2E7D8A76ED395 (user_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // activity_log table
        $this->addSql('CREATE TABLE activity_log (
            id INT AUTO_INCREMENT NOT NULL,
            user_id INT NOT NULL,
            action VARCHAR(100) NOT NULL,
            subject_id VARCHAR(255) DEFAULT NULL,
            details VARCHAR(255) DEFAULT NULL,
            context JSON DEFAULT NULL,
            created_at DATETIME_IMMUTABLE NOT NULL,
            INDEX idx_activity_user (user_id),
            INDEX idx_activity_action (action),
            INDEX idx_activity_created (created_at),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // foreign keys
        $this->addSql('ALTER TABLE import_batch ADD CONSTRAINT FK_40C2E7D8A76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE activity_log ADD CONSTRAINT FK_AC66C65CA76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE import_batch DROP FOREIGN KEY FK_40C2E7D8A76ED395');
        $this->addSql('ALTER TABLE activity_log DROP FOREIGN KEY FK_AC66C65CA76ED395');
        $this->addSql('DROP TABLE import_batch');
        $this->addSql('DROP TABLE activity_log');
    }
}
