<?php

namespace App\Service;

use App\Dto\AlertFilterDTO;
use App\Entity\Alert;
use App\Entity\User;
use App\Enum\UserRoleEnum;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

/**
 * Service pour construire QueryBuilder avec filtres d'alerte.
 * Centralise toute la logique de filtrage et d'autorisation.
 */
class AlertFilterService
{
    private EntityManagerInterface $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    /**
     * Construit un QueryBuilder pour les alertes avec tous les filtres.
     * Gère automatiquement les autorisations rôle.
     *
     * @param AlertFilterDTO $dto Les filtres
     * @param User $user L'utilisateur courant
     * @param string $alias L'alias de l'entité (default: 'a')
     * @param bool $selectDistinct whether to use DISTINCT
     * @return QueryBuilder
     */
    public function buildQueryBuilder(AlertFilterDTO $dto, User $user, string $alias = 'a', bool $selectDistinct = true): QueryBuilder
    {
        $qb = $this->entityManager->createQueryBuilder();

        // select() doit toujours être défini — sans lui, Doctrine génère "SELECT DISTINCT FROM ..."
        $qb->select($alias)
           ->from(Alert::class, $alias);

        if ($selectDistinct) {
            $qb->distinct(true);
        }

        // Jointures obligatoires
        $qb->leftJoin($alias . '.market', 'm')
           ->leftJoin($alias . '.emetteur', 'e')
           ->leftJoin($alias . '.validatedBy', 'vb');

        // Gestion des autorisations rôle
        $this->applyRoleAuthorization($qb, $alias, $user);

        // Appliquer tous les filtres
        $this->applyFilters($qb, $alias, $dto);

        return $qb;
    }

    /**
     * Applique les filtres de l'entité AlertRepository::findForUser() mais de manière centralisée.
     */
    private function applyFilters(QueryBuilder $qb, string $alias, AlertFilterDTO $dto): void
    {
        // Statut
        if ($dto->getStatut()) {
            $qb->andWhere($alias . '.statut = :statut')
               ->setParameter('statut', $dto->getStatut());
        }

        // Priorité
        if ($dto->getNiveauPriorite()) {
            $qb->andWhere($alias . '.niveauPriorite = :priorite')
               ->setParameter('priorite', $dto->getNiveauPriorite());
        }

        // Marché (entity)
        if ($dto->getMarket()) {
            $qb->andWhere($alias . '.market = :market')
               ->setParameter('market', $dto->getMarket());
        }

        // Recherche (code, zone, catégorie, résumé)
        if ($dto->getSearch()) {
            $qb->andWhere(
                $qb->expr()->orX(
                    $qb->expr()->like($alias . '.codeGei', ':search'),
                    $qb->expr()->like($alias . '.resumeExecutif', ':search'),
                    $qb->expr()->like($alias . '.portCorridor', ':search')
                )
            )
            ->setParameter('search', '%' . $dto->getSearch() . '%');
        }

        // Catégorie
        if ($dto->getCategorie()) {
            $qb->andWhere($alias . '.categorie = :categorie')
               ->setParameter('categorie', $dto->getCategorie());
        }

        // Urgence
        if ($dto->getUrgence()) {
            $qb->andWhere($alias . '.urgence = :urgence')
               ->setParameter('urgence', $dto->getUrgence());
        }

        // Score minimum
        if ($dto->getScoreMin() !== null) {
            $qb->andWhere($qb->expr()->gte('COALESCE(' . $alias . '.scoreSurcharge, ' . $alias . '.scoreGei)', ':scoreMin'))
               ->setParameter('scoreMin', $dto->getScoreMin());
        }

        // Score maximum
        if ($dto->getScoreMax() !== null) {
            $qb->andWhere($qb->expr()->lte('COALESCE(' . $alias . '.scoreSurcharge, ' . $alias . '.scoreGei)', ':scoreMax'))
               ->setParameter('scoreMax', $dto->getScoreMax());
        }

        // Origine
        if ($dto->getOrigine()) {
            $qb->andWhere($alias . '.origine = :origine')
               ->setParameter('origine', $dto->getOrigine());
        }

        // Date de début
        if ($dto->getDateDebut()) {
            $qb->andWhere($qb->expr()->gte($alias . '.dateCreation', ':dateDebut'))
               ->setParameter('dateDebut', new \DateTime($dto->getDateDebut() . ' 00:00:00'));
        }

        // Date de fin
        if ($dto->getDateFin()) {
            $qb->andWhere($qb->expr()->lte($alias . '.dateCreation', ':dateFin'))
               ->setParameter('dateFin', new \DateTime($dto->getDateFin() . ' 23:59:59'));
        }

        // Urgence 72h uniquement
        if ($dto->getUrgence72h() === true) {
            $qb->andWhere($alias . '.urgence = :urg72h')
               ->setParameter('urg72h', '72h');
        }

        // Agent (émetteur)
        if ($dto->getAgent()) {
            $qb->andWhere($alias . '.emetteur = :agent')
               ->setParameter('agent', $dto->getAgent());
        }

        // Manager (validateur)
        if ($dto->getManager()) {
            $qb->andWhere($alias . '.validatedBy = :manager')
               ->setParameter('manager', $dto->getManager());
        }
    }

    /**
     * Applique l'autorisation rôle.
     * - EMETTEUR_TERRAIN : ses propres alertes uniquement
     * - PFT : alertes de ses marchés gérés
     * - Autres : toutes les alertes
     */
    private function applyRoleAuthorization(QueryBuilder $qb, string $alias, User $user): void
    {
        $role = $user->getRole();

        if ($role === UserRoleEnum::EMETTEUR_TERRAIN) {
            // AGENT : ses propres alertes uniquement
            $qb->andWhere($alias . '.emetteur = :currentUser')
               ->setParameter('currentUser', $user);
        } elseif ($role === UserRoleEnum::PFT) {
            // MANAGER : alertes de ses marchés gérés
            $managedMarkets = $user->getAllManagedMarkets();
            if (!empty($managedMarkets)) {
                $qb->andWhere($alias . '.market IN (:managedMarkets)')
                   ->setParameter('managedMarkets', $managedMarkets);
            } else {
                // Aucun marché géré → aucune alerte visible
                $qb->andWhere('1 = 0');
            }
        }
        // SUPERADMIN, SAHOLTY, COMITE_AIT, SECRETARIAT_GEI → toutes les alertes
    }

    /**
     * Applique le tri, la pagination et la clause deletedAt au QueryBuilder.
     */
    public function applyPaginationAndOrder(QueryBuilder $qb, AlertFilterDTO $dto, string $alias = 'a'): QueryBuilder
    {
        $triChampsAutorisés = ['dateCreation', 'scoreGei', 'niveauPriorite', 'statut', 'market'];

        $triChamp = $dto->getTri() ?? 'dateCreation';
        $triSens = strtoupper($dto->getTriSens() ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

        if (!in_array($triChamp, $triChampsAutorisés, true)) {
            $triChamp = 'dateCreation';
        }

        // Exclure les alertes soft-delete AVANT le tri (ordre important en DQL)
        $qb->andWhere($alias . '.deletedAt IS NULL');

        $qb->orderBy($alias . '.' . $triChamp, $triSens);

        // Pagination
        $page = max(1, $dto->getPage());
        $limit = max(1, $dto->getLimit());
        $offset = ($page - 1) * $limit;

        $qb->setFirstResult($offset)
           ->setMaxResults($limit);

        return $qb;
    }

    /**
     * Construit un QueryBuilder complet avec tri, pagination et soft-delete.
     */
    public function buildCompleteQueryBuilder(AlertFilterDTO $dto, User $user): QueryBuilder
    {
        $qb = $this->buildQueryBuilder($dto, $user);
        return $this->applyPaginationAndOrder($qb, $dto);
    }
}
