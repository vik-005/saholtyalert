<?php

namespace App\Enum;

enum NiveauPriorite: string
{
    case CRITIQUE = 'critique';
    case ELEVE = 'eleve';
    case MODERE = 'modere';
    case FAIBLE = 'faible';

    public function label(): string
    {
        return match($this) {
            self::CRITIQUE => 'Critique',
            self::ELEVE => 'Élevé',
            self::MODERE => 'Modéré',
            self::FAIBLE => 'Faible',
        };
    }

    public function badgeClass(): string
    {
        return match($this) {
            self::CRITIQUE => 'priority-critique',
            self::ELEVE => 'priority-eleve',
            self::MODERE => 'priority-modere',
            self::FAIBLE => 'priority-faible',
        };
    }

    public function icon(): string
    {
        return match($this) {
            self::CRITIQUE => '🔴',
            self::ELEVE => '🟠',
            self::MODERE => '🟡',
            self::FAIBLE => '🟢',
        };
    }

    /** Détermine le niveau de priorité depuis un score GEI (Annexe C) */
    public static function fromScore(int $score): self
    {
        return match(true) {
            $score >= 18 => self::CRITIQUE,
            $score >= 14 => self::ELEVE,
            $score >= 10 => self::MODERE,
            default => self::FAIBLE,
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
