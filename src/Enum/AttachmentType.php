<?php

namespace App\Enum;

enum AttachmentType: string
{
    case DOCUMENT = 'document';
    case PHOTO = 'photo';
    case TRACKING = 'tracking';
    case TEMOIGNAGE = 'temoignage';
    case RAPPORT = 'rapport';
    case AUTRE = 'autre';

    public function label(): string
    {
        return match($this) {
            self::DOCUMENT => 'Document officiel',
            self::PHOTO => 'Photo / Image',
            self::TRACKING => 'Données de tracking',
            self::TEMOIGNAGE => 'Témoignage',
            self::RAPPORT => 'Rapport',
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
