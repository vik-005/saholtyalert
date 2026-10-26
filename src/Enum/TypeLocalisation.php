<?php

namespace App\Enum;

enum TypeLocalisation: string
{
    case PORT = 'port';
    case CORRIDOR = 'corridor';
    case AEROPORT = 'aeroport';
    case LOCALITE = 'localite';

    public function label(): string
    {
        return match ($this) {
            self::PORT => 'Port',
            self::CORRIDOR => 'Corridor',
            self::AEROPORT => 'Aéroport',
            self::LOCALITE => 'Localité',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::PORT => 'bi-anchor',
            self::CORRIDOR => 'bi-signpost-figure',
            self::AEROPORT => 'bi-airplane',
            self::LOCALITE => 'bi-geo-alt',
        };
    }
}
