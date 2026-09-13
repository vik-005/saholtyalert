<?php

namespace App\Dto;

use App\Entity\User;
use Symfony\Component\HttpFoundation\Request;

class AuditFilterDTO
{
    public ?\DateTimeInterface $dateFrom = null;
    public ?\DateTimeInterface $dateTo = null;
    public array $markets = [];
    public array $corridors = [];
    public array $categories = [];
    public array $typeSources = [];
    public array $statuts = [];
    public array $urgences = [];
    public array $niveauxPriorite = [];
    public ?string $typeAlerte = null;
    public ?int $scoreMin = null;
    public ?int $scoreMax = null;
    public ?int $agentId = null;
    public ?int $managerId = null;
    public string $operateur = '';
    public string $marque = '';
    public string $texteLibre = '';
    public ?string $origine = null;
    public string $vue = 'texte';
    public int $page = 1;
    public int $limit = 50;

    public static function fromRequest(Request $request): self
    {
        $data = array_merge($request->query->all(), $request->request->all());

        $dto = new self();

        // Backward-compat : ancien paramètre `pays` → `markets`
        $markets = $data['markets'] ?? $data['pays'] ?? [];
        if (!empty($markets) && is_array($markets)) {
            $dto->markets = array_map('intval', $markets);
        }

        try {
            $fromVal = $data['dateFrom'] ?? $data['anneeDebut'] ?? null;
            if (!empty($fromVal)) {
                $fromStr = trim((string) $fromVal);
                if (preg_match('/^\d{4}$/', $fromStr)) {
                    $dto->dateFrom = new \DateTimeImmutable($fromStr . '-01-01 00:00:00');
                } else {
                    $dto->dateFrom = new \DateTimeImmutable($fromStr . ' 00:00:00');
                }
            }

            $toVal = $data['dateTo'] ?? $data['anneeFin'] ?? null;
            if (!empty($toVal)) {
                $toStr = trim((string) $toVal);
                if (preg_match('/^\d{4}$/', $toStr)) {
                    $dto->dateTo = (new \DateTimeImmutable($toStr . '-12-31 23:59:59'))->modify('+1 second');
                } else {
                    // Borne exclusive : inclut toute la journée sélectionnée.
                    $dto->dateTo = (new \DateTimeImmutable($toStr . ' 00:00:00'))->modify('+1 day');
                }
            }
        } catch (\Exception) {
            $dto->dateFrom = null;
            $dto->dateTo = null;
        }
        if (!empty($data['corridors']) && is_array($data['corridors'])) {
            $dto->corridors = array_values(array_filter(array_map('trim', $data['corridors'])));
        }
        if (!empty($data['categories']) && is_array($data['categories'])) {
            $dto->categories = $data['categories'];
        }
        if (!empty($data['typeSources']) && is_array($data['typeSources'])) {
            $dto->typeSources = $data['typeSources'];
        }
        if (!empty($data['statuts']) && is_array($data['statuts'])) {
            $dto->statuts = $data['statuts'];
        }
        if (!empty($data['urgences']) && is_array($data['urgences'])) {
            $dto->urgences = $data['urgences'];
        }
        if (!empty($data['niveauxPriorite']) && is_array($data['niveauxPriorite'])) {
            $dto->niveauxPriorite = $data['niveauxPriorite'];
        }
        if (!empty($data['typeAlerte'])) {
            $dto->typeAlerte = $data['typeAlerte'];
        }
        if (isset($data['scoreMin']) && $data['scoreMin'] !== '') {
            $dto->scoreMin = (int) $data['scoreMin'];
        }
        if (isset($data['scoreMax']) && $data['scoreMax'] !== '') {
            $dto->scoreMax = (int) $data['scoreMax'];
        }
        if ($dto->scoreMin !== null && $dto->scoreMax !== null && $dto->scoreMin > $dto->scoreMax) {
            [$dto->scoreMin, $dto->scoreMax] = [$dto->scoreMax, $dto->scoreMin];
        }
        if (!empty($data['agentId'])) {
            $dto->agentId = (int) $data['agentId'];
        }
        if (!empty($data['managerId'])) {
            $dto->managerId = (int) $data['managerId'];
        }
        if (!empty($data['operateur'])) {
            $dto->operateur = trim($data['operateur']);
        }
        if (!empty($data['marque'])) {
            $dto->marque = trim($data['marque']);
        }
        if (!empty($data['texteLibre'])) {
            $dto->texteLibre = trim($data['texteLibre']);
        }
        if (!empty($data['origine'])) {
            $dto->origine = $data['origine'];
        }
        if (!empty($data['vue'])) {
            $dto->vue = $data['vue'];
        }
        if (!empty($data['page'])) {
            $dto->page = max(1, (int) $data['page']);
        }
        if (!empty($data['limit'])) {
            $dto->limit = min(100, max(1, (int) $data['limit']));
        }

        return $dto;
    }

    public function hasActiveFilters(): bool
    {
        return !empty($this->markets)
            || !empty($this->corridors)
            || !empty($this->categories)
            || !empty($this->typeSources)
            || !empty($this->statuts)
            || !empty($this->urgences)
            || !empty($this->niveauxPriorite)
            || !empty($this->scoreMin)
            || !empty($this->scoreMax)
            || !empty($this->agentId)
            || !empty($this->managerId)
            || $this->operateur !== ''
            || $this->marque !== ''
            || $this->texteLibre !== ''
            || !empty($this->origine)
            || !empty($this->dateFrom)
            || !empty($this->dateTo);
    }

    public function cacheKey(string $suffix = '', ?int $userId = null): string
    {
        $hash = md5(serialize([
            'userId' => $userId,
            'markets' => $this->markets,
            'corridors' => $this->corridors,
            'categories' => $this->categories,
            'typeSources' => $this->typeSources,
            'statuts' => $this->statuts,
            'urgences' => $this->urgences,
            'niveauxPriorite' => $this->niveauxPriorite,
            'typeAlerte' => $this->typeAlerte,
            'scoreMin' => $this->scoreMin,
            'scoreMax' => $this->scoreMax,
            'agentId' => $this->agentId,
            'managerId' => $this->managerId,
            'operateur' => $this->operateur,
            'marque' => $this->marque,
            'texteLibre' => $this->texteLibre,
            'origine' => $this->origine,
            'dateFrom' => $this->dateFrom?->format('Y-m-d'),
            'dateTo' => $this->dateTo?->modify('-1 day')->format('Y-m-d'),
        ]));

        return 'audit_' . $hash . '_' . $suffix;
    }

    public function toQueryParams(): array
    {
        return [
            'dateFrom' => $this->dateFrom?->format('Y-m-d'),
            'dateTo' => $this->dateTo?->format('Y-m-d'),
            'markets' => $this->markets,
            'corridors' => $this->corridors,
            'categories' => $this->categories,
            'typeSources' => $this->typeSources,
            'statuts' => $this->statuts,
            'urgences' => $this->urgences,
            'niveauxPriorite' => $this->niveauxPriorite,
            'typeAlerte' => $this->typeAlerte,
            'scoreMin' => $this->scoreMin,
            'scoreMax' => $this->scoreMax,
            'agentId' => $this->agentId,
            'managerId' => $this->managerId,
            'operateur' => $this->operateur,
            'marque' => $this->marque,
            'texteLibre' => $this->texteLibre,
            'origine' => $this->origine,
            'vue' => $this->vue,
        ];
    }
}
