<?php

namespace App\Repository;

use App\Entity\Alert;
use App\Entity\AlertComment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class AlertCommentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AlertComment::class);
    }

    /**
     * @return AlertComment[] Retourne les commentaires d'une alerte, du plus récent au plus ancien
     */
    public function findByAlert(Alert $alert): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.alert = :alert')
            ->setParameter('alert', $alert)
            ->orderBy('c.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
