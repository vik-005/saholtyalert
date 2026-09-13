<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ajout de la table connection_position pour la géolocalisation à la connexion.
 */
final class Version20260826000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Création de la table connection_position pour la géolocalisation des connexions';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE connection_position (
            id INT AUTO_INCREMENT NOT NULL,
            user_id INT NOT NULL,
            latitude DECIMAL(10, 6) NOT NULL,
            longitude DECIMAL(10, 6) NOT NULL,
            method VARCHAR(20) NOT NULL,
            ip_address VARCHAR(45) DEFAULT NULL,
            connected_at DATETIME NOT NULL,
            INDEX IDX_5C42D6A7A76ED395 (user_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE connection_position ADD CONSTRAINT FK_5C42D6A7A76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE connection_position');
    }
}
