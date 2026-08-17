<?php

namespace App\Enum;

/**
 * Types de source GEI — conformes aux valeurs réelles du registre Annexe B.
 * Valeurs ajoutées après audit (17/08/2026) :
 *   source_portuaire, source_frontiere, statistiques_douanieres,
 *   manifeste_maritime, source_institutionnelle
 */
enum TypeSource: string
{
    case TERRAIN                  = 'terrain';
    case SOURCE_PORTUAIRE         = 'source_portuaire';        // ajout audit
    case SOURCE_FRONTIERE         = 'source_frontiere';        // ajout audit
    case INSTITUTION              = 'institution';
    case SOURCE_INSTITUTIONNELLE  = 'source_institutionnelle'; // ajout audit (synonyme institution)
    case OPEN_SOURCE              = 'open_source';
    case MANIFESTE_PORTUAIRE      = 'manifeste_portuaire';
    case MANIFESTE_MARITIME       = 'manifeste_maritime';      // ajout audit
    case DECLARATION_DOUANIERE    = 'declaration_douaniere';
    case STATISTIQUES_DOUANIERES  = 'statistiques_douanieres'; // ajout audit
    case AUTRE                    = 'autre';

    public function label(): string
    {
        return match($this) {
            self::TERRAIN                 => 'Source terrain',
            self::SOURCE_PORTUAIRE        => 'Source portuaire',
            self::SOURCE_FRONTIERE        => 'Source frontière',
            self::INSTITUTION             => 'Institution',
            self::SOURCE_INSTITUTIONNELLE => 'Source institutionnelle',
            self::OPEN_SOURCE             => 'Open Source / Presse',
            self::MANIFESTE_PORTUAIRE     => 'Manifeste portuaire',
            self::MANIFESTE_MARITIME      => 'Manifeste maritime',
            self::DECLARATION_DOUANIERE   => 'Déclaration douanière',
            self::STATISTIQUES_DOUANIERES => 'Statistiques douanières',
            self::AUTRE                   => 'Autre',
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
