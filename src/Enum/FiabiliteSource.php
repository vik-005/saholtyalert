<?php

namespace App\Enum;

enum FiabiliteSource: string
{
    case A = 'A';
    case B = 'B';
    case C = 'C';
    case D = 'D';

    public function label(): string
    {
        return match($this) {
            self::A => 'A — Source connue et fiable',
            self::B => 'B — Source généralement fiable',
            self::C => 'C — Source assez fiable',
            self::D => 'D — Source non vérifiée',
        };
    }

    /** Score pour le calcul GEI (Annexe C) */
    public function score(): int
    {
        return match($this) {
            self::A => 4,
            self::B => 3,
            self::C => 2,
            self::D => 1,
        };
    }

    public function badgeClass(): string
    {
        return match($this) {
            self::A => 'badge-success',
            self::B => 'badge-info',
            self::C => 'badge-warning',
            self::D => 'badge-danger',
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
