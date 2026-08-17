<?php

namespace App\Enum;

/**
 * Niveaux d'impact GEI — conformes aux valeurs réelles du registre Annexe B.
 * Valeurs ajoutées après audit (17/08/2026) :
 *   tres_eleve ("Très élevé"), moyen_a_eleve ("Moyen à élevé")
 */
enum AlertImpact: string
{
    case TRES_ELEVE   = 'tres_eleve';   // ajout audit — "Très élevé" dans registre réel
    case ELEVE        = 'eleve';
    case MOYEN_ELEVE  = 'moyen_eleve';  // ajout audit — "Moyen à élevé" dans registre réel
    case MOYEN        = 'moyen';
    case FAIBLE       = 'faible';

    public function label(): string
    {
        return match($this) {
            self::TRES_ELEVE  => 'Très élevé',
            self::ELEVE       => 'Élevé',
            self::MOYEN_ELEVE => 'Moyen à élevé',
            self::MOYEN       => 'Moyen',
            self::FAIBLE      => 'Faible',
        };
    }

    /**
     * Score Annexe C.
     * Très élevé = 3 (même que Élevé — pas de niveau 4 dans la matrice).
     * Moyen à élevé = 2 (arrondi conservateur).
     */
    public function score(): int
    {
        return match($this) {
            self::TRES_ELEVE  => 3,
            self::ELEVE       => 3,
            self::MOYEN_ELEVE => 2,
            self::MOYEN       => 2,
            self::FAIBLE      => 1,
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
