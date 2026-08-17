<?php

namespace App\Enum;

enum TypeAlerte: string
{
    case OPERATIONNELLE = 'operationnelle';
    case STRATEGIQUE = 'strategique';

    public function label(): string
    {
        return match($this) {
            self::OPERATIONNELLE => 'Opérationnelle',
            self::STRATEGIQUE => 'Stratégique',
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
