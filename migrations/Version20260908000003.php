<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ajout de la colonne created_by_manager_id sur la table user (Partie C).
 * Permet de traçabilité de qui a créé un compte Agent (Manager créateur).
 */
final class Version20260908000003 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajout de created_by_manager_id sur user (Partie C — traçabilité Manager créateur)';
    }

    public function up(Schema $schema): void
    {
        // Ajout de la colonne created_by_manager_id sur la table user
        if (!$schema->getTable('user')->hasColumn('created_by_manager_id')) {
            $schema->getTable('user')->addColumn('created_by_manager_id', 'integer', ['notnull' => false]);
            $schema->getTable('user')->addForeignKeyConstraint(
                'user',
                ['created_by_manager_id'],
                ['id'],
                ['onDelete' => 'SET NULL']
            );
        }
    }

    public function down(Schema $schema): void
    {
        $schema->getTable('user')->dropForeignKey('user_ibfk_created_by_manager');
        $schema->getTable('user')->dropColumn('created_by_manager_id');
    }
}
