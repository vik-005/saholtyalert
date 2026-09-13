<?php

namespace App\Enum;

/**
 * Statuts du workflow GEI — conformes aux valeurs réelles du registre Annexe B.
 * Valeurs ajoutées après audit (17/08/2026) :
 *   ouvert ("Ouvert"), en_cours_analyse ("En cours d'analyse"), ouvert_prioritaire
 */
enum AlertStatut: string
{
    case NOUVEAU              = 'nouveau';
    case OUVERT               = 'ouvert';              // ajout audit — "Ouvert" dans registre réel
    case EN_COURS             = 'en_cours';
    case EN_COURS_ANALYSE     = 'en_cours_analyse';    // ajout audit — "En cours d'analyse"
    case A_COMPLETER          = 'a_completer';
    case A_INVESTIGUER        = 'a_investiguer';
    case OUVERT_PRIORITAIRE   = 'ouvert_prioritaire';  // ajout audit — "Ouvert – prioritaire"
    case A_VALIDER_SAHOLTY    = 'a_valider_saholty';
    /** Validation finale par le Manager du marché. */
    case VALIDEE              = 'validee';
    case TRANSMIS             = 'transmis';
    case SUIVI                = 'suivi';
    case ARCHIVE              = 'archive';
    case CLOS                 = 'clos';

    public function label(): string
    {
        return match($this) {
            self::NOUVEAU              => 'Nouveau',
            self::OUVERT               => 'Ouvert',
            self::EN_COURS             => 'En cours',
            self::EN_COURS_ANALYSE     => "En cours d'analyse",
            self::A_COMPLETER          => 'À compléter',
            self::A_INVESTIGUER        => 'À investiguer',
            self::OUVERT_PRIORITAIRE   => 'Ouvert – prioritaire',
            self::A_VALIDER_SAHOLTY    => 'En attente de validation Manager',
            self::VALIDEE              => 'Validée',
            self::TRANSMIS             => 'Transmis',
            self::SUIVI                => 'Suivi',
            self::ARCHIVE              => 'Archivé',
            self::CLOS                 => 'Clos',
        };
    }

    public function badgeClass(): string
    {
        return match($this) {
            self::NOUVEAU              => 'badge-info',
            self::OUVERT               => 'badge-info',
            self::EN_COURS             => 'badge-primary',
            self::EN_COURS_ANALYSE     => 'badge-primary',
            self::A_COMPLETER          => 'badge-warning',
            self::A_INVESTIGUER        => 'badge-warning',
            self::OUVERT_PRIORITAIRE   => 'badge-danger',
            self::A_VALIDER_SAHOLTY    => 'badge-orange',
            self::VALIDEE              => 'badge-success',
            self::TRANSMIS             => 'badge-success',
            self::SUIVI                => 'badge-secondary',
            self::ARCHIVE              => 'badge-muted',
            self::CLOS                 => 'badge-muted',
        };
    }

    /** Indique si le statut est un état "actif" (non clos, non archivé) */
    public function isActif(): bool
    {
        return !in_array($this, [self::ARCHIVE, self::CLOS], true);
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
