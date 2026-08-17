<?php

namespace App\Enum;

enum PhaseUrgence: string
{
    case DETECTION = 'detection';
    case QUALIFICATION = 'qualification';
    case VALIDATION = 'validation';
    case COORDINATION = 'coordination';
    case SUIVI = 'suivi';

    public function label(): string
    {
        return match($this) {
            self::DETECTION => 'Phase 0 — Détection',
            self::QUALIFICATION => 'Phase 1 — Qualification rapide',
            self::VALIDATION => 'Phase 2 — Validation & orientation',
            self::COORDINATION => 'Phase 3 — Coordination opérationnelle',
            self::SUIVI => 'Phase 4 — Suivi & clôture',
        };
    }

    /** Heure limite SLA depuis T0 (Annexe E) */
    public function slaHeures(): int
    {
        return match($this) {
            self::DETECTION => 0,
            self::QUALIFICATION => 6,
            self::VALIDATION => 24,
            self::COORDINATION => 48,
            self::SUIVI => 72,
        };
    }

    /** Heure de rappel automatique (avant la limite SLA) */
    public function rapppelHeures(): int
    {
        return match($this) {
            self::DETECTION => 0,
            self::QUALIFICATION => 5,    // rappel à T+5h (limite T+6h)
            self::VALIDATION => 22,      // rappel à T+22h (limite T+24h)
            self::COORDINATION => 46,    // rappel à T+46h
            self::SUIVI => 70,           // rappel à T+70h
        };
    }

    public function ordre(): int
    {
        return match($this) {
            self::DETECTION => 0,
            self::QUALIFICATION => 1,
            self::VALIDATION => 2,
            self::COORDINATION => 3,
            self::SUIVI => 4,
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
