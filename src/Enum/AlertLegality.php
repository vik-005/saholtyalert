<?php

namespace App\Enum;

enum AlertLegality: string
{
    case LICITE = 'licite';
    case ILLICITE = 'illicite';

    public function label(): string
    {
        return match ($this) {
            self::LICITE => 'Licite',
            self::ILLICITE => 'Illicite',
        };
    }
}
