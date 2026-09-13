<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Finalisation Manager et audit explicite des évolutions de score. */
final class Version20260908000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute les scores avant/après recalcul et introduit le statut validee après validation Manager.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE alert_qualification_history ADD old_score INT DEFAULT NULL, ADD new_score INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE alert_qualification_history DROP old_score, DROP new_score');
    }
}
