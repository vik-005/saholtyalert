<?php

namespace App\Repository;

use App\Entity\Urgence72hCase;
use App\Enum\AlertStatut;
use App\Enum\AlertUrgence;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Urgence72hCase>
 */
class Urgence72hCaseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Urgence72hCase::class);
    }

    public function findActiveCases(): array
    {
        return $this->createQueryBuilder('c')
            ->leftJoin('c.alert', 'a')
            ->leftJoin('c.phases', 'p')
            ->addSelect('a', 'p')
            ->where('c.statutCase = :active')
            ->andWhere('a.urgence = :urgence72h')
            ->andWhere('a.statut = :validee')
            ->setParameter('active', 'active')
            ->setParameter('urgence72h', AlertUrgence::SOIXANTE_DOUZE_H)
            ->setParameter('validee', AlertStatut::VALIDEE)
            ->orderBy('c.dateActivation', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
