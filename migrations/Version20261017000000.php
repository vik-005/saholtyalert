<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261017000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the persisted profile photo path to user accounts.';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable('user') && $schema->getTable('user')->hasColumn('photo_profil')) {
            return;
        }

        $this->addSql('ALTER TABLE `user` ADD photo_profil VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // Keep existing profile-photo paths and files when rolling back this migration.
    }
}
