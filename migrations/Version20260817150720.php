<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260817150720 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE alert CHANGE categorie categorie VARCHAR(255) NOT NULL, CHANGE type_source type_source VARCHAR(255) DEFAULT NULL, CHANGE impact impact VARCHAR(255) DEFAULT NULL, CHANGE exploitabilite exploitabilite VARCHAR(255) DEFAULT NULL, CHANGE statut statut VARCHAR(255) NOT NULL, CHANGE emetteur_texte emetteur_texte VARCHAR(200) DEFAULT NULL, CHANGE pieces_type pieces_type VARCHAR(100) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE alert CHANGE categorie categorie VARCHAR(50) DEFAULT NULL COMMENT \'AlertCategorie Enum\', CHANGE type_source type_source VARCHAR(50) DEFAULT NULL COMMENT \'TypeSource Enum\', CHANGE emetteur_texte emetteur_texte VARCHAR(200) DEFAULT NULL COMMENT \'Texte libre émetteur importé depuis registre Excel\', CHANGE pieces_type pieces_type VARCHAR(100) DEFAULT NULL COMMENT \'Type de pièces dispo (ex: manifeste, photos)\', CHANGE impact impact VARCHAR(20) DEFAULT NULL COMMENT \'AlertImpact Enum\', CHANGE exploitabilite exploitabilite VARCHAR(20) DEFAULT NULL COMMENT \'AlertExploitabilite Enum\', CHANGE statut statut VARCHAR(30) DEFAULT \'nouveau\' NOT NULL COMMENT \'AlertStatut Enum\'');
    }
}
