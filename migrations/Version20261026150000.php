<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261026150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute les noms et coordonnées des localités aux alertes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE alert ADD localite VARCHAR(150) DEFAULT NULL, ADD type_localite VARCHAR(20) DEFAULT NULL, ADD localite_latitude DOUBLE PRECISION DEFAULT NULL, ADD localite_longitude DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_alert_localite ON alert (localite)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_alert_localite ON alert');
        $this->addSql('ALTER TABLE alert DROP localite, DROP type_localite, DROP localite_latitude, DROP localite_longitude');
    }
}
