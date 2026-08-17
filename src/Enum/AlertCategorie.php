<?php

namespace App\Enum;

/**
 * Catégories d'alerte GEI — conformes aux valeurs réelles du registre Annexe B.
 * Valeurs ajoutées après audit du fichier Excel réel (17/08/2026) :
 *   tabac_brut, importation_tabac, signal_faible, transit_cigarettes,
 *   tabac_manufacture, signalement_operationnel, controle_cargaison,
 *   controle_documentaire, saisies_consolidees, incohérence_declaration,
 *   suivi_operationnel, renseignement_logistique, fret_express, mouvement_transfrontalier
 */
enum AlertCategorie: string
{
    // Catégories originales
    case CONTAINER_SUSPECT        = 'container_suspect';
    case FLUX_TERRESTRE           = 'flux_terrestre';
    case ACTEUR                   = 'acteur';
    case MODUS_OPERANDI           = 'modus_operandi';
    case TRANSIT_TABAC            = 'transit_tabac';
    case SAISIE                   = 'saisie';
    case VEILLE_MARCHE            = 'veille_marche';

    // Catégories ajoutées après audit registre réel Annexe B
    case TABAC_BRUT               = 'tabac_brut';
    case IMPORTATION_TABAC        = 'importation_tabac';
    case TRANSIT_CIGARETTES       = 'transit_cigarettes';
    case TABAC_MANUFACTURE        = 'tabac_manufacture';
    case SIGNAL_FAIBLE            = 'signal_faible';
    case SIGNALEMENT_OPERATIONNEL = 'signalement_operationnel';
    case CONTROLE_CARGAISON       = 'controle_cargaison';
    case CONTROLE_DOCUMENTAIRE    = 'controle_documentaire';
    case SAISIES_CONSOLIDEES      = 'saisies_consolidees';
    case INCOHERENCE_DECLARATION  = 'incoherence_declaration';
    case SUIVI_OPERATIONNEL       = 'suivi_operationnel';
    case RENSEIGNEMENT_LOGISTIQUE = 'renseignement_logistique';
    case FRET_EXPRESS             = 'fret_express';
    case MOUVEMENT_TRANSFRONTALIER= 'mouvement_transfrontalier';
    case AUTRE                    = 'autre';

    public function label(): string
    {
        return match($this) {
            self::CONTAINER_SUSPECT        => 'Conteneur suspect',
            self::FLUX_TERRESTRE           => 'Flux terrestre',
            self::ACTEUR                   => 'Acteur suspect',
            self::MODUS_OPERANDI           => 'Modus operandi',
            self::TRANSIT_TABAC            => 'Transit tabac',
            self::SAISIE                   => 'Saisie / Interception',
            self::VEILLE_MARCHE            => 'Veille marché',
            self::TABAC_BRUT               => 'Tabac brut',
            self::IMPORTATION_TABAC        => 'Importation tabac',
            self::TRANSIT_CIGARETTES       => 'Transit cigarettes',
            self::TABAC_MANUFACTURE        => 'Tabac manufacturé',
            self::SIGNAL_FAIBLE            => 'Signal faible / Statistique',
            self::SIGNALEMENT_OPERATIONNEL => 'Signalement opérationnel',
            self::CONTROLE_CARGAISON       => 'Contrôle de cargaison',
            self::CONTROLE_DOCUMENTAIRE    => 'Contrôle documentaire',
            self::SAISIES_CONSOLIDEES      => 'Saisies consolidées',
            self::INCOHERENCE_DECLARATION  => 'Incohérence de déclaration',
            self::SUIVI_OPERATIONNEL       => 'Suivi opérationnel',
            self::RENSEIGNEMENT_LOGISTIQUE => 'Renseignement logistique',
            self::FRET_EXPRESS             => 'Fret express / Colis',
            self::MOUVEMENT_TRANSFRONTALIER=> 'Mouvement transfrontalier',
            self::AUTRE                    => 'Autre',
        };
    }

    public static function choices(): array
    {
        $choices = [];
        foreach (self::cases() as $case) {
            $choices[$case->label()] = $case->value;
        }
        return $choices;
    }
}
