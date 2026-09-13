<?php

namespace App\Repository;

use App\Entity\ConnectionPosition;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ConnectionPosition>
 */
class ConnectionPositionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ConnectionPosition::class);
    }

    /**
     * Récupère les positions des utilisateurs d'un Manager (ses Agents).
     */
    public function findForManager(User $manager): array
    {
        $qb = $this->createQueryBuilder('cp')
            ->join('cp.user', 'u')
            ->where('u.responsable = :manager OR u.role IN (:roles)')
            ->setParameter('manager', $manager)
            ->setParameter('roles', ['EMETTEUR_TERRAIN']);

        // Si le manager gère des marchés, filtrer sur les Agents de ces marchés
        $managedMarkets = $manager->getAllManagedMarkets();
        if (!empty($managedMarkets)) {
            $qb->andWhere('u.market IN (:markets)')
               ->setParameter('markets', $managedMarkets);
        }

        return $qb
            ->orderBy('cp.connectedAt', 'DESC')
            ->setMaxResults(100)
            ->getQuery()
            ->getResult();
    }

    /**
     * Récupère les positions par période pour visualisation sur carte.
     */
    public function findByPeriod(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        return $this->createQueryBuilder('cp')
            ->where('cp.connectedAt BETWEEN :from AND :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('cp.connectedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
