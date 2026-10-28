<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261028120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Centralise les destinataires de transmission et préserver leurs codes historiques';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE liste_reference_valeur ADD valeur VARCHAR(150) DEFAULT NULL');

        $institutions = [
            ['Direction Générale des Douanes (DGD)', 'DGD', 1],
            ['Police Nationale / Interpol', 'POLICE', 2],
            ['Gendarmerie Nationale', 'GENDARMERIE', 3],
            ['Administration Fiscale / Impôts', 'FISC', 4],
            ['Partenaire International (Interpol / WCO)', 'PARTENAIRE_INT', 5],
            ['Autorité Portuaire / Maritime', 'PORT', 6],
            ['Autre autorité compétente', 'AUTRE', 7],
        ];

        foreach ($institutions as [$label, $value, $order]) {
            $quotedLabel = $this->connection->quote($label);
            $quotedValue = $this->connection->quote($value);
            $this->addSql(sprintf(
                "UPDATE liste_reference_valeur SET valeur = %s "
                . "WHERE type_liste = 'institution_transmission' AND libelle = %s AND valeur IS NULL",
                $quotedValue,
                $quotedLabel,
            ));
            $this->addSql(sprintf(
                "INSERT INTO liste_reference_valeur (type_liste, libelle, valeur, actif, ordre_affichage, created_at, updated_at) "
                . "SELECT 'institution_transmission', %s, %s, 1, %d, NOW(), NOW() "
                . "WHERE NOT EXISTS (SELECT 1 FROM liste_reference_valeur WHERE type_liste = 'institution_transmission' AND valeur = %s)",
                $quotedLabel,
                $quotedValue,
                $order,
                $quotedValue,
            ));
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE liste_reference_valeur DROP valeur');
    }
}
