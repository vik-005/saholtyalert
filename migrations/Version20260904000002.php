<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Correction de la colonne resume_executif sur la table alert.
 *
 * Problème : la colonne existait dans la base de données initiale (avant le système de migrations)
 * en tant que VARCHAR(255) NOT NULL, alors que l'entité Alert déclare :
 *   #[ORM\Column(type: 'text', nullable: true)]
 *   private ?string $resumeExecutif = null;
 *
 * La migration de création (Version20260817150720) a élargi d'autres colonnes à VARCHAR(255)
 * mais a oublié resume_executif. Le type LONGTEXT DEFAULT NULL est requis pour :
 *   - Permettre des résumés longs (>255 caractères, bien que la validation limite à 500)
 *   - Respecter l'annotation nullable=true de l'entité
 *
 * Corrige l'erreur SQLSTATE[23000] : 1048 Column 'resume_executif' cannot be null
 */
final class Version20260904000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Corrige resume_executif en LONGTEXT DEFAULT NULL (entité nullable=true)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE alert CHANGE resume_executif resume_executif LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE alert CHANGE resume_executif resume_executif VARCHAR(255) NOT NULL');
    }
}