<?php

namespace App\Dto;

use Symfony\Component\HttpFoundation\Request;

/**
 * DTO de filtre pour la page /corridors (Analyses & Cartes).
 *
 * Encapsule : période, marchés sélectionnés, type de localisation.
 * Résout le bug Symfony "Input value 'markets' contains a non-scalar value"
 * en normalisant correctement le tableau de marchés issu de la requête HTTP.
 */
final class CorridorFilterDTO
{
    public string $dateDebut;
    public string $dateFin;
    /** @var int[] */
    public array $marketIds = [];
    /** @var string null | 'corridor' | 'port' | 'aeroport' */
    public ?string $typeLoc;

    /**
     * Construit le DTO depuis la requête HTTP courante.
     * Accepte markets[] (checkbox array) ou markets=1,2,3 (comma-separated).
     *
     * @param array<int, \App\Entity\Market> $allMarkets Tous les marchés actifs
     */
    public static function fromRequest(Request $request, array $allMarkets): self
    {
        $dto = new self();

        // ── Période ──────────────────────────────────────────────────────────
        $dto->dateFin   = $request->query->get('fin',   (new \DateTime())->format('Y-m-d'));
        $dto->dateDebut = $request->query->get('debut', (new \DateTime('-90 days'))->format('Y-m-d'));

        // Sécurité : date de début ne peut pas être après la date de fin
        if ($dto->dateDebut > $dto->dateFin) {
            $dto->dateDebut = $dto->dateFin;
        }

        // ── Marchés ──────────────────────────────────────────────────────────
        // IMPORTANT : utiliser query->all() pour éviter "non-scalar value"
        $all    = $request->query->all();
        $raw    = $all['markets'] ?? null;

        if ($raw === null) {
            // Aucun paramètre => tous les marchés (comportement par défaut)
            $dto->marketIds = array_map(fn($m) => $m->getId(), $allMarkets);
        } elseif (is_array($raw)) {
            // Formulaire avec checkbox markets[] → tableau direct
            $dto->marketIds = array_values(array_filter(array_map('intval', $raw)));
        } elseif (is_string($raw) && $raw !== '') {
            // Paramètre textuel comma-separated markets=1,2,3
            $dto->marketIds = array_values(array_filter(array_map('intval', explode(',', $raw))));
        } else {
            $dto->marketIds = [];
        }

        // ── Type de localisation ──────────────────────────────────────────────
        $type = $request->query->get('type_loc', '');
        $dto->typeLoc = in_array($type, ['corridor', 'port', 'aeroport'], true) ? $type : null;

        return $dto;
    }

    /**
     * Retourne true si le filtre est "Tous" (aucun type spécifique).
     */
    public function isAllTypes(): bool
    {
        return $this->typeLoc === null;
    }

    /**
     * Retourne true si au moins un marché est sélectionné.
     */
    public function hasMarkets(): bool
    {
        return !empty($this->marketIds);
    }

    /**
     * Label humain du type pour l'affichage dans l'Empty State.
     */
    public function typeLabel(): string
    {
        return match ($this->typeLoc) {
            'corridor' => 'Corridor',
            'port'     => 'Port',
            'aeroport' => 'Aéroport',
            default    => 'Tous',
        };
    }

    /**
     * Label descriptif du titre de la page selon le type.
     */
    public function pageTitle(): string
    {
        return match ($this->typeLoc) {
            'corridor' => 'Corridors de transit',
            'port'     => 'Ports',
            'aeroport' => 'Aéroports',
            default    => 'Corridors, Ports & Aéroports',
        };
    }

    /**
     * Sous-titre de la page.
     */
    public function pageSubtitle(): string
    {
        return match ($this->typeLoc) {
            'corridor' => 'Analyse des corridors de transit',
            'port'     => 'Analyse des ports',
            'aeroport' => 'Analyse des aéroports',
            default    => 'Analyse globale des alertes',
        };
    }

    /**
     * Titre du graphique principal selon le type.
     */
    public function chartTitle(): string
    {
        return match ($this->typeLoc) {
            'corridor' => 'Top corridors de transit',
            'port'     => 'Top ports',
            'aeroport' => 'Top aéroports',
            default    => 'Top corridors, ports & aéroports',
        };
    }

    /**
     * Sous-titre du graphique selon le type.
     */
    public function chartSubtitle(): string
    {
        return match ($this->typeLoc) {
            'corridor' => 'Fréquence des alertes par corridor',
            'port'     => 'Fréquence des alertes par port',
            'aeroport' => 'Fréquence des alertes par aéroport',
            default    => 'Fréquence des alertes (tous types confondus)',
        };
    }

    /**
     * Label singulier de l'élément.
     */
    public function itemLabel(): string
    {
        return match ($this->typeLoc) {
            'corridor' => 'corridor',
            'port'     => 'port',
            'aeroport' => 'aéroport',
            default    => 'élément',
        };
    }

    /**
     * Icône Bootstrap Icons pour le type.
     */
    public function typeIcon(): string
    {
        return match ($this->typeLoc) {
            'corridor' => 'bi-signpost-2',
            'port'     => 'bi-anchor',
            'aeroport' => 'bi-airplane',
            default    => 'bi-map',
        };
    }
}
