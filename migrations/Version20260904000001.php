<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Pré-alimentation des listes administrables avec les valeurs réelles
 * fournies par le client (Annexe B) — 20 catégories + 16 types de source.
 *
 * Remplace les anciennes valeurs seedées par AppFixtures pour garantir
 * la conformité métier sur tous les environnements (dev, recette, prod).
 *
 * Note : addSql() dans Doctrine Migrations n'accepte PAS de paramètres nommés.
 * On utilise des valeurs littérales échappées directement dans le SQL.
 */
final class Version20260904000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Pré-alimentation listes administrables avec valeurs réelles client (20 catégories + 16 types de source)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("DELETE FROM liste_reference_valeur WHERE type_liste IN ('categorie', 'type_source')");

        $values = [
            // Catégorie (champ 9 du formulaire agent)
            ['categorie', 'Transit de cigarettes', 1],
            ['categorie', 'Transit de tabac brut', 2],
            ['categorie', 'Transit de cigares', 3],
            ['categorie', 'Importation de cigarettes', 4],
            ['categorie', 'Importation de tabac', 5],
            ['categorie', 'Réexportation de produits du tabac', 6],
            ['categorie', 'Mouvement transfrontalier de tabac', 7],
            ['categorie', 'Saisie de produits du tabac', 8],
            ['categorie', 'Interception / controle de cargaison', 9],
            ['categorie', 'Regularisation douaniere', 10],
            ['categorie', 'Absence de declaration douaniere', 11],
            ['categorie', 'Anomalie documentaire', 12],
            ['categorie', 'Fausse declaration presumee', 13],
            ['categorie', 'Incoherence d\'origine / destination', 14],
            ['categorie', 'Exportateur ou destinataire non identifie', 15],
            ['categorie', 'Dissimulation du beneficiaire reel', 16],
            ['categorie', 'Recurrence de flux / operateur', 17],
            ['categorie', 'Schema de transit suspect', 18],
            ['categorie', 'Renseignement / signalement', 19],
            ['categorie', 'Veille / analyse de marche', 20],
            // Type de source (champ 11 du formulaire agent)
            ['type_source', 'Source douaniere', 1],
            ['type_source', 'Declaration douaniere', 2],
            ['type_source', 'Manifeste transporteur', 3],
            ['type_source', 'Connaissement / Bill of Lading', 4],
            ['type_source', 'Document de transport', 5],
            ['type_source', 'Document commercial', 6],
            ['type_source', 'Document administratif', 7],
            ['type_source', 'Rapport institutionnel', 8],
            ['type_source', 'Rapport de mission', 9],
            ['type_source', 'Rapport d\'enquete', 10],
            ['type_source', 'Renseignement de terrain', 11],
            ['type_source', 'Information d\'un partenaire institutionnel', 12],
            ['type_source', 'Information d\'un consultant / correspondant', 13],
            ['type_source', 'Donnee portuaire', 14],
            ['type_source', 'Donnee aeroportuaire', 15],
            ['type_source', 'Source ouverte (OSINT)', 16],
        ];

        foreach ($values as [$typeListe, $libelle, $ordre]) {
            $this->addSql(sprintf(
                "INSERT INTO liste_reference_valeur (type_liste, libelle, actif, ordre_affichage, created_at, updated_at)
                 VALUES (%s, %s, 1, %d, NOW(), NOW())",
                $this->connection->quote($typeListe, ParameterType::STRING),
                $this->connection->quote($libelle, ParameterType::STRING),
                $ordre
            ));
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM liste_reference_valeur WHERE type_liste IN ('categorie', 'type_source')");
    }
}
