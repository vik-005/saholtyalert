<?php

namespace App\Enum;

enum Sensibilite: string
{
    case INTERNE = 'interne';
    case RESTREINTE = 'restreinte';
    case CONFIDENTIELLE = 'confidentielle';

    public function label(): string
    {
        return match($this) {
            self::INTERNE => 'Interne',
            self::RESTREINTE => 'Restreinte',
            self::CONFIDENTIELLE => 'Confidentielle',
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
