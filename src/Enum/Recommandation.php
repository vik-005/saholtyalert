<?php

namespace App\Enum;

enum Recommandation: string
{
    case SURVEILLER = 'surveiller';
    case APPROFONDIR = 'approfondir';
    case TRANSMISSION = 'transmission';
    case URGENCE_OPERATIONNELLE = 'urgence_operationnelle';

    public function label(): string
    {
        return match($this) {
            self::SURVEILLER => 'Surveiller',
            self::APPROFONDIR => 'Approfondir l\'investigation',
            self::TRANSMISSION => 'Transmission aux autorités',
            self::URGENCE_OPERATIONNELLE => 'Urgence opérationnelle',
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
