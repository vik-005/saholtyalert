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
            self::PORT => 'anchor',
            self::CORRIDOR => 'signpost-2',
            self::AEROPORT => 'airplane',
            self::LOCALITE => 'pin-map-fill',
        };
    }

    public function cssClass(): string
    {
        return 'bi bi-' . $this->icon();
    }
}
