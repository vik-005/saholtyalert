<?php

namespace App\Enum;

enum AlertUrgence: string
{
    case IMMEDIAT = 'immediat';
    case SOIXANTE_DOUZE_H = '72h';
    case ROUTINE = 'routine';

    public function label(): string
    {
        return match($this) {
            self::IMMEDIAT => 'Immédiat',
            self::SOIXANTE_DOUZE_H => '72h',
            self::ROUTINE => 'Routine',
        };
    }

    /** Score pour le calcul GEI (Annexe C) */
    public function score(): int
    {
        return match($this) {
            self::IMMEDIAT => 3,
            self::SOIXANTE_DOUZE_H => 2,
            self::ROUTINE => 1,
        };
    }

    public function badgeClass(): string
    {
        return match($this) {
            self::IMMEDIAT => 'badge-danger',
            self::SOIXANTE_DOUZE_H => 'badge-warning',
            self::ROUTINE => 'badge-info',
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
