<?php

namespace App\Enum;

/** Niveaux d'impact GEI retenus par la matrice de qualification. */
enum AlertImpact: string
{
    case ELEVE = 'eleve';
    case MOYEN = 'moyen';
    case FAIBLE = 'faible';

    public function label(): string
    {
        return match($this) {
            self::ELEVE => 'Élevé',
            self::MOYEN => 'Moyen',
            self::FAIBLE => 'Faible',
        };
    }

    /** Score Annexe C. */
    public function score(): int
    {
        return match($this) {
            self::ELEVE => 3,
            self::MOYEN => 2,
            self::FAIBLE => 1,
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
