<?php

namespace App\Repository;

use App\Entity\Market;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Market>
 */
class MarketRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Market::class);
    }

    /**
     * Retourne uniquement les marchés actifs — utilisé dans TOUS les selects du système.
     * Toute liste déroulante de pays doit appeler cette méthode, jamais findAll().
     */
    public function findActifs(): array
    {
        return $this->findBy(['actif' => true], ['nom' => 'ASC']);
    }

    /**
     * Recherche par nom ou code ISO3 (pour les filtres et la page Audit).
     */
    public function findBySearch(string $q): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.nom LIKE :q OR m.codeIso3 LIKE :q')
            ->setParameter('q', '%' . $q . '%')
            ->andWhere('m.actif = true')
            ->orderBy('m.nom', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Retourne tous les marchés avec leur nombre d'alertes (actives, non supprimées).
     * Utilisé dans les statistiques et la carte.
     */
    public function findWithAlertCount(): array
    {
        return $this->createQueryBuilder('m')
            ->leftJoin('App\Entity\Alert', 'a', 'WITH',
                'a.market = m AND a.deletedAt IS NULL'
            )
            ->addSelect('COUNT(a.id) as alertCount')
            ->where('m.actif = true')
            ->groupBy('m.id')
            ->orderBy('alertCount', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
