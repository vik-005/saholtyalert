<?php

namespace App\Dto;

/**
 * DTO pour encapsuler tous les filtres d'alerte.
 * Centralise la logique de filtrage dans une structure fortement typée.
 */
class AlertFilterDTO
{
    // ─────────────────────────────────────────────────────────────────────────
    // STATUT & PRIORITÉ
    // ─────────────────────────────────────────────────────────────────────────

    /** Statut de l'alerte */
    public ?string $statut = null;

    /** Niveau de priorité */
    public ?string $niveauPriorite = null;

    // ─────────────────────────────────────────────────────────────────────────
    // MARCHÉ & GÉOGRAPHIE
    // ─────────────────────────────────────────────────────────────────────────

    /** ID du marché */
    public ?int $market = null;

    /** Recherche libre (code, zone, catégorie, résumé) */
    public ?string $search = null;

    /** Catégorie d'alerte */
    public ?string $categorie = null;

    /** Type d'urgence */
    public ?string $urgence = null;

    // ─────────────────────────────────────────────────────────────────────────
    // SCORE
    // ─────────────────────────────────────────────────────────────────────────

    /** Score minimum */
    public ?int $scoreMin = null;

    /** Score maximum */
    public ?int $scoreMax = null;

    // ─────────────────────────────────────────────────────────────────────────
    // ORIGINE & DATES
    // ─────────────────────────────────────────────────────────────────────────

    /** Origine : saisie_agent ou import_excel */
    public ?string $origine = null;

    /** Date de création minimale (format Y-m-d) */
    public ?string $dateDebut = null;

    /** Date de création maximale (format Y-m-d) */
    public ?string $dateFin = null;

    // ─────────────────────────────────────────────────────────────────────────
    // URGENCE 72H
    // ─────────────────────────────────────────────────────────────────────────

    /** Filtre urgence 72h uniquement */
    public ?bool $urgence72h = null;

    // ─────────────────────────────────────────────────────────────────────────
    // AGENTS & MANAGERS (Partie B)
    // ─────────────────────────────────────────────────────────────────────────

    /** Agent émetteur (ID) */
    public ?int $agent = null;

    /** Manager validateur (ID) */
    public ?int $manager = null;

    // ─────────────────────────────────────────────────────────────────────────
    // TRI & PAGINATION
    // ─────────────────────────────────────────────────────────────────────────

    /** Champ de tri : dateCreation, scoreGei, niveauPriorite, statut, market */
    public ?string $tri = null;

    /** Sens de tri : ASC ou DESC */
    public ?string $triSens = null;

    /** Numéro de page (pour pagination) */
    public int $page = 1;

    /** Limite d'éléments par page */
    public int $limit = 10;

    // ─────────────────────────────────────────────────────────────────────────
    // GETTERS & SETTERS
    // ─────────────────────────────────────────────────────────────────────────

    public function getStatut(): ?string
    {
        return $this->statut;
    }

    public function setStatut(?string $statut): self
    {
        $this->statut = $statut;
        return $this;
    }

    public function getNiveauPriorite(): ?string
    {
        return $this->niveauPriorite;
    }

    public function setNiveauPriorite(?string $niveauPriorite): self
    {
        $this->niveauPriorite = $niveauPriorite;
        return $this;
    }

    public function getMarket(): ?int
    {
        return $this->market;
    }

    public function setMarket(?int $market): self
    {
        $this->market = $market;
        return $this;
    }

    public function getSearch(): ?string
    {
        return $this->search;
    }

    public function setSearch(?string $search): self
    {
        $this->search = $search;
        return $this;
    }

    public function getCategorie(): ?string
    {
        return $this->categorie;
    }

    public function setCategorie(?string $categorie): self
    {
        $this->categorie = $categorie;
        return $this;
    }

    public function getUrgence(): ?string
    {
        return $this->urgence;
    }

    public function setUrgence(?string $urgence): self
    {
        $this->urgence = $urgence;
        return $this;
    }

    public function getScoreMin(): ?int
    {
        return $this->scoreMin;
    }

    public function setScoreMin(?int $scoreMin): self
    {
        $this->scoreMin = $scoreMin;
        return $this;
    }

    public function getScoreMax(): ?int
    {
        return $this->scoreMax;
    }

    public function setScoreMax(?int $scoreMax): self
    {
        $this->scoreMax = $scoreMax;
        return $this;
    }

    public function getOrigine(): ?string
    {
        return $this->origine;
    }

    public function setOrigine(?string $origine): self
    {
        $this->origine = $origine;
        return $this;
    }

    public function getDateDebut(): ?string
    {
        return $this->dateDebut;
    }

    public function setDateDebut(?string $dateDebut): self
    {
        $this->dateDebut = $dateDebut;
        return $this;
    }

    public function getDateFin(): ?string
    {
        return $this->dateFin;
    }

    public function setDateFin(?string $dateFin): self
    {
        $this->dateFin = $dateFin;
        return $this;
    }

    public function getUrgence72h(): ?bool
    {
        return $this->urgence72h;
    }

    public function setUrgence72h(?bool $urgence72h): self
    {
        $this->urgence72h = $urgence72h;
        return $this;
    }

    public function getAgent(): ?int
    {
        return $this->agent;
    }

    public function setAgent(?int $agent): self
    {
        $this->agent = $agent;
        return $this;
    }

    public function getManager(): ?int
    {
        return $this->manager;
    }

    public function setManager(?int $manager): self
    {
        $this->manager = $manager;
        return $this;
    }

    public function getTri(): ?string
    {
        return $this->tri;
    }

    public function setTri(?string $tri): self
    {
        $this->tri = $tri;
        return $this;
    }

    public function getTriSens(): ?string
    {
        return $this->triSens;
    }

    public function setTriSens(?string $triSens): self
    {
        $this->triSens = $triSens;
        return $this;
    }

    public function getPage(): int
    {
        return $this->page;
    }

    public function setPage(int $page): self
    {
        $this->page = $page;
        return $this;
    }

    public function getLimit(): int
    {
        return $this->limit;
    }

    public function setLimit(int $limit): self
    {
        $this->limit = $limit;
        return $this;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // HELPER METHODS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Indique si le filtre a au moins un critère actif.
     */
    public function hasActiveFilters(): bool
    {
        $properties = (new \ReflectionClass($this))->getProperties();
        foreach ($properties as $property) {
            $name = $property->getName();
            if ($name === 'page' || $name === 'limit' || $name === 'tri' || $name === 'triSens') {
                continue; // Ignorer les paramètres de pagination et de tri
            }
            $value = $this->$name;
            if ($value !== null && $value !== '') {
                return true;
            }
        }
        return false;
    }

    /**
     * Convertir en tableau pour compatibilité ascendante.
     */
    public function toArray(): array
    {
        return [
            'statut' => $this->statut,
            'niveauPriorite' => $this->niveauPriorite,
            'market' => $this->market,
            'search' => $this->search,
            'categorie' => $this->categorie,
            'urgence' => $this->urgence,
            'scoreMin' => $this->scoreMin,
            'scoreMax' => $this->scoreMax,
            'origine' => $this->origine,
            'dateDebut' => $this->dateDebut,
            'dateFin' => $this->dateFin,
            'urgence72h' => $this->urgence72h,
            'agent' => $this->agent,
            'manager' => $this->manager,
            'tri' => $this->tri,
            'triSens' => $this->triSens,
            'page' => $this->page,
            'limit' => $this->limit,
        ];
    }
}
