<?php

namespace App\Repository;

use App\Entity\AccessLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AccessLog>
 */
class AccessLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AccessLog::class);
    }

    public function findLatestLogs(int $limit = 100): array
    {
        return $this->createQueryBuilder('l')
            ->leftJoin('l.user', 'u')
            ->leftJoin('l.alert', 'a')
            ->addSelect('u', 'a')
            ->orderBy('l.dateAction', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Recherche paginée avec filtres — utilisée par ActivityController.
     */
    public function findFiltered(array $filters = [], int $page = 1, int $perPage = 50): array
    {
        $qb = $this->createQueryBuilder('l')
            ->leftJoin('l.user', 'u')
            ->leftJoin('l.alert', 'a')
            ->addSelect('u', 'a')
            ->orderBy('l.dateAction', 'DESC');

        if (!empty($filters['userId'])) {
            $qb->andWhere('l.user = :userId')
               ->setParameter('userId', $filters['userId']);
        }

        if (!empty($filters['action'])) {
            $qb->andWhere('l.action = :action')
               ->setParameter('action', $filters['action']);
        }

        if (!empty($filters['alertCode'])) {
            $qb->andWhere('a.codeGei LIKE :code')
               ->setParameter('code', '%' . $filters['alertCode'] . '%');
        }

        if (!empty($filters['dateDebut'])) {
            $qb->andWhere('l.dateAction >= :debut')
               ->setParameter('debut', new \DateTimeImmutable($filters['dateDebut'] . ' 00:00:00'));
        }

        if (!empty($filters['dateFin'])) {
            $qb->andWhere('l.dateAction <= :fin')
               ->setParameter('fin', new \DateTimeImmutable($filters['dateFin'] . ' 23:59:59'));
        }

        $total = (clone $qb)
            ->select('COUNT(l.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $results = $qb
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        return [
            'logs'       => $results,
            'total'      => (int) $total,
            'page'       => $page,
            'perPage'    => $perPage,
            'totalPages' => (int) ceil($total / $perPage),
        ];
    }
}
