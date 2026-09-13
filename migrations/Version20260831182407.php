<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260831182407 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE alert_comment (id INT AUTO_INCREMENT NOT NULL, contenu LONGTEXT NOT NULL, type VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL, alert_id INT NOT NULL, auteur_id INT NOT NULL, INDEX IDX_A27F5AE60BB6FE6 (auteur_id), INDEX idx_ac_alert (alert_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE liste_reference_valeur (id INT AUTO_INCREMENT NOT NULL, type_liste VARCHAR(50) NOT NULL, libelle VARCHAR(150) NOT NULL, actif TINYINT NOT NULL, ordre_affichage INT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, cree_par_id INT DEFAULT NULL, INDEX IDX_42ECBCE8FC29C013 (cree_par_id), INDEX idx_lrv_type (type_liste), INDEX idx_lrv_actif (actif), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE alert_comment ADD CONSTRAINT FK_A27F5AE93035F72 FOREIGN KEY (alert_id) REFERENCES alert (id)');
        $this->addSql('ALTER TABLE alert_comment ADD CONSTRAINT FK_A27F5AE60BB6FE6 FOREIGN KEY (auteur_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE liste_reference_valeur ADD CONSTRAINT FK_42ECBCE8FC29C013 FOREIGN KEY (cree_par_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE alert ADD marque VARCHAR(150) DEFAULT NULL, ADD decision_gei LONGTEXT DEFAULT NULL, CHANGE categorie categorie VARCHAR(150) DEFAULT NULL, CHANGE type_source type_source VARCHAR(150) DEFAULT NULL, CHANGE anonymisation anonymisation VARCHAR(10) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE alert_comment DROP FOREIGN KEY FK_A27F5AE93035F72');
        $this->addSql('ALTER TABLE alert_comment DROP FOREIGN KEY FK_A27F5AE60BB6FE6');
        $this->addSql('ALTER TABLE liste_reference_valeur DROP FOREIGN KEY FK_42ECBCE8FC29C013');
        $this->addSql('DROP TABLE alert_comment');
        $this->addSql('DROP TABLE liste_reference_valeur');
        $this->addSql('ALTER TABLE alert DROP marque, DROP decision_gei, CHANGE categorie categorie VARCHAR(255) NOT NULL, CHANGE type_source type_source VARCHAR(255) DEFAULT NULL, CHANGE anonymisation anonymisation VARCHAR(255) NOT NULL');
    }
}
