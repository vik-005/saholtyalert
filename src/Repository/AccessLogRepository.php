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
}
