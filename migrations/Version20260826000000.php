<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ajout de la table zone_geo pour la cartographie Leaflet.
 */
final class Version20260826000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Création de la table zone_geo pour la cartographie des alertes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE zone_geo (
            id INT AUTO_INCREMENT NOT NULL,
            market_id INT NOT NULL,
            latitude DECIMAL(10, 6) NOT NULL,
            longitude DECIMAL(10, 6) NOT NULL,
            polygon_wkt LONGTEXT DEFAULT NULL,
            nom VARCHAR(100) NOT NULL,
            INDEX IDX_A6C7D3C88D9F6D38 (market_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE zone_geo ADD CONSTRAINT FK_A6C7D3C88D9F6D38 FOREIGN KEY (market_id) REFERENCES market (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE zone_geo');
    }
}
