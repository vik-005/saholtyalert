<?php

namespace App\Service;

use App\Dto\AuditFilterDTO;
use App\Entity\User;
use App\Repository\AlertAuditRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Cache\CacheInterface;

class AuditService
{
    public function __construct(
        private readonly AlertAuditRepository $auditRepo,
        private readonly EntityManagerInterface $em,
        private readonly CacheInterface $cache,
    ) {}

    public function applyUserScope(AuditFilterDTO $dto, User $user): AuditFilterDTO
    {
        // L'agent ne peut pas accéder au module Audit (hors scope)
        if ($user->getRole() === \App\Enum\UserRoleEnum::EMETTEUR_TERRAIN) {
            // Limiter strictement à ses propres alertes
            $dto->agentId = $user->getId();
            return $dto;
        }

        // Manager : limiter à ses marchés gérés (sauf si filtre marché explicite hors scope)
        if ($user->getRole() === \App\Enum\UserRoleEnum::PFT) {
            $managedMarkets = $user->getAllManagedMarkets();
            if (!empty($managedMarkets)) {
                $managedIds = array_map(fn($m) => $m->getId(), $managedMarkets);
                // Si l'utilisateur a filtré des marchés, ne garder que ceux dans son scope
                if (!empty($dto->markets)) {
                    $dto->markets = array_values(array_intersect($dto->markets, $managedIds));
                }
            }
        }

        return $dto;
    }

    public function getResults(AuditFilterDTO $dto, User $user): array
    {
        $dto = $this->applyUserScope($dto, $user);

        $total = $this->auditRepo->countByFilters($dto, $user);
        $rows = $this->auditRepo->findPaginated($dto, $user);
        $stats = $this->auditRepo->aggregateStats($dto, $user);

        return [
            'total' => $total,
            'page' => $dto->page,
            'limit' => $dto->limit,
            'pages' => (int) ceil($total / $dto->limit),
            'rows' => $rows,
            'stats' => $stats,
            'filters' => $dto,
        ];
    }

    public function getAggregations(AuditFilterDTO $dto, User $user): array
    {
        $dto = $this->applyUserScope($dto, $user);
        return $this->auditRepo->aggregateStats($dto, $user);
    }

    public function getChartData(AuditFilterDTO $dto, User $user): array
    {
        $stats = $this->getAggregations($dto, $user);

        // Legacy format pour rétro-compatibilité avec les templates existants
        // Les graphiques JS attendent { 'label': count } pas les rows complètes
        return [
            'total'          => $stats['total'],
            'scoreMoyen'     => $stats['scoreMoyen'],
            'critiques'      => $stats['critiques'],
            'tauxTransmission' => $stats['tauxTransmission'],
            'parPays'        => array_column($stats['parMarche'], 'nb', 'nom'),
            'parCategorie'   => array_column($stats['parCategorie'], 'nb', 'categorie'),
            'parPriorite'    => $this->formatPrioriteLabels($stats['parNiveauPriorite']),
            'parAnnee'       => array_column($stats['parMois'], 'nb', 'mois'),
            'parStatut'      => array_column($stats['parStatut'], 'nb', 'statut'),
            'parAgent'       => array_column($stats['parAgent'], 'nb', 'agent'),
            'parUrgence'     => array_column($stats['parUrgence'], 'nb', 'urgence'),
            'parExploitabilite' => array_column($stats['parExploitabilite'], 'nb', 'exploitabilite'),
            'donneesCartographie' => $stats['donneesCartographie'],
        ];
    }

    /**
     * Formatte les labels de priorité pour l'affichage (critique → Critique).
     */
    private function formatPrioriteLabels(array $parNiveauPriorite): array
    {
        $result = [];
        foreach ($parNiveauPriorite as $row) {
            $label = ucfirst(strtolower($row['niveauPriorite'] ?? ''));
            // Gestion des accents pour l'affichage
            $label = match(strtolower($row['niveauPriorite'] ?? '')) {
                'critique' => 'Critique',
                'eleve' => 'Élevé',
                'modere' => 'Modéré',
                'faible' => 'Faible',
                default => $label,
            };
            $result[$label] = (int) $row['nb'];
        }
        return $result;
    }

    public function searchPaginated(AuditFilterDTO $dto, User $user): array
    {
        $dto = $this->applyUserScope($dto, $user);
        $items = $this->auditRepo->findPaginated($dto, $user);
        $total = $this->auditRepo->countByFilters($dto, $user);

        return [
            'items' => $items,
            'total' => $total,
            'page' => $dto->page,
            'limit' => $dto->limit,
            'pages' => (int) ceil($total / $dto->limit),
        ];
    }

    public function getCorridorSuggestions(array $marketIds): array
    {
        if (empty($marketIds)) {
            return [];
        }

        $sql = "SELECT DISTINCT a.port_corridor as value, COUNT(*) as count
                FROM alert a
                WHERE a.deleted_at IS NULL
                  AND a.port_corridor IS NOT NULL
                  AND a.port_corridor != ''
                  AND a.market_id IN (:marketIds)
                GROUP BY a.port_corridor
                ORDER BY count DESC, a.port_corridor ASC
                LIMIT 50";

        return $this->conn()->fetchAllAssociative($sql, ['marketIds' => $marketIds]);
    }

    public function getAgentsInScope(User $user): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('u.id, u.prenom, u.nom, u.email')
            ->from(\App\Entity\User::class, 'u');

        if ($user->getRole() === \App\Enum\UserRoleEnum::PFT) {
            $managedMarkets = $user->getAllManagedMarkets();
            if (!empty($managedMarkets)) {
                $managedIds = array_map(fn($m) => $m->getId(), $managedMarkets);
                $qb->where('u.market IN (:markets)')
                   ->setParameter('markets', $managedMarkets);
            }
        }

        $qb->andWhere('u.actif = :actif')
           ->setParameter('actif', true)
           ->orderBy('u.nom', 'ASC')
           ->setMaxResults(100);

        return $qb->getQuery()->getResult();
    }

    public function getAllManagers(): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('u.id, u.prenom, u.nom')
            ->from(\App\Entity\User::class, 'u')
            ->where('u.role IN (:roles)')
            ->setParameter('roles', [
                \App\Enum\UserRoleEnum::PFT,
                \App\Enum\UserRoleEnum::SAHOLTY,
                \App\Enum\UserRoleEnum::SUPERADMIN,
            ])
            ->andWhere('u.actif = :actif')
            ->setParameter('actif', true)
            ->orderBy('u.nom', 'ASC');

        return $qb->getQuery()->getResult();
    }

    private function conn(): \Doctrine\DBAL\Connection
    {
        return $this->em->getConnection();
    }

    public function invalidateCache(): void
    {
        // Invalidation globale — nécessaire après import/modification massive
        // Le cache utilise des clés hashées, on ne peut pas les invalider individuellement
        // On utilise donc un tag global ou un TTL court
    }
}
