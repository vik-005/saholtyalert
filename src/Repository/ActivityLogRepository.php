<?php

namespace App\Repository;

use App\Entity\ActivityLog;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ActivityLog>
 */
class ActivityLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ActivityLog::class);
    }

    public function save(ActivityLog $log): void
    {
        $this->getEntityManager()->persist($log);
        $this->getEntityManager()->flush();
    }

    public function findByUser(User $user, int $limit = 50, ?string $action = null): array
    {
        $qb = $this->createQueryBuilder('al')
            ->where('al.user = :user')
            ->setParameter('user', $user)
            ->orderBy('al.createdAt', 'DESC')
            ->setMaxResults($limit);

        if ($action) {
            $qb->andWhere('al.action = :action')
                ->setParameter('action', $action);
        }

        return $qb->getQuery()->getResult();
    }

    public function findRecent(int $days = 7, ?string $action = null): array
    {
        $qb = $this->createQueryBuilder('al')
            ->where('al.createdAt >= :date')
            ->setParameter('date', new DateTimeImmutable("-{$days} days"))
            ->orderBy('al.createdAt', 'DESC');

        if ($action) {
            $qb->andWhere('al.action = :action')
                ->setParameter('action', $action);
        }

        return $qb->getQuery()->getResult();
    }

    public function findLatest(int $limit = 20): array
    {
        return $this->createQueryBuilder('al')
            ->orderBy('al.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countByAction(string $action): int
    {
        return (int) $this->createQueryBuilder('al')
            ->select('COUNT(al.id)')
            ->where('al.action = :action')
            ->setParameter('action', $action)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
