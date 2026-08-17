<?php

namespace App\Enum;

enum AnonymisationNiveau: string
{
    case ELEVE = 'eleve';
    case PARTIEL = 'partiel';
    case FAIBLE = 'faible';

    public function label(): string
    {
        return match($this) {
            self::ELEVE => 'Élevé (par défaut)',
            self::PARTIEL => 'Partiel',
            self::FAIBLE => 'Faible (habilitation requise)',
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
