<?php

namespace App\Enum;

enum TransmissionStatut: string
{
    case OUI = 'oui';
    case NON = 'non';
    case A_VALIDER = 'a_valider';

    public function label(): string
    {
        return match($this) {
            self::OUI => 'Transmis',
            self::NON => 'Non transmis',
            self::A_VALIDER => 'À valider',
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
