<?php

namespace App\Enum;

enum PhaseUrgence: string
{
    case DETECTION = 'detection';
    case COORDINATION = 'coordination';
    case SUIVI = 'suivi';

    public function label(): string
    {
        return match($this) {
            self::DETECTION => 'Détection',
            self::COORDINATION => 'Coordination opérationnelle',
            self::SUIVI => 'Suivi & clôture',
        };
    }

    public function phaseNumero(): string
    {
        return match($this) {
            self::DETECTION => 'Phase 0',
            self::COORDINATION => 'Phase 1',
            self::SUIVI => 'Phase 2',
        };
    }

    public function slaLabel(): string
    {
        return match($this) {
            self::DETECTION => 'T0',
            self::COORDINATION => 'T+48h',
            self::SUIVI => 'T+72h',
        };
    }

    /** Heure limite SLA depuis T0 (Annexe E) */
    public function slaHeures(): int
    {
        return match($this) {
            self::DETECTION => 0,
            self::COORDINATION => 48,
            self::SUIVI => 72,
        };
    }

    /** Heure de rappel automatique (avant la limite SLA) */
    public function rapppelHeures(): int
    {
        return match($this) {
            self::DETECTION => 0,
            self::COORDINATION => 44,    // rappel à T+44h (limite T+48h)
            self::SUIVI => 68,           // rappel à T+68h (limite T+72h)
        };
    }

    public function ordre(): int
    {
        return match($this) {
            self::DETECTION => 0,
            self::COORDINATION => 1,
            self::SUIVI => 2,
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
