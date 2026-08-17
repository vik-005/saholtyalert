<?php

namespace App\Enum;

enum ActorRole: string
{
    case EXPEDITEUR = 'expediteur';
    case DESTINATAIRE = 'destinataire';
    case TRANSPORTEUR = 'transporteur';
    case INTERMEDIAIRE = 'intermediaire';
    case AUTRE = 'autre';

    public function label(): string
    {
        return match($this) {
            self::EXPEDITEUR => 'Expéditeur',
            self::DESTINATAIRE => 'Destinataire',
            self::TRANSPORTEUR => 'Transporteur',
            self::INTERMEDIAIRE => 'Intermédiaire',
            self::AUTRE => 'Autre',
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
