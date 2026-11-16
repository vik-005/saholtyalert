<?php

namespace App\Repository;

use App\Entity\SecurityAlert;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SecurityAlert>
 */
class SecurityAlertRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SecurityAlert::class);
    }

    /**
     * Find recent alerts
     */
    public function findRecent(int $limit = 50): array
    {
        return $this->createQueryBuilder('sa')
            ->orderBy('sa.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find unacknowledged alerts
     */
    public function findUnacknowledged(): array
    {
        return $this->createQueryBuilder('sa')
            ->where('sa.status = :status')
            ->setParameter('status', SecurityAlert::STATUS_NEW)
            ->orderBy('sa.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find critical alerts
     */
    public function findCritical(): array
    {
        return $this->createQueryBuilder('sa')
            ->where('sa.severity = :severity')
            ->setParameter('severity', SecurityAlert::SEVERITY_CRITICAL)
            ->orderBy('sa.createdAt', 'DESC')
            ->setMaxResults(100)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find by type
     */
    public function findByType(string $alertType): array
    {
        return $this->createQueryBuilder('sa')
            ->where('sa.alertType = :alertType')
            ->setParameter('alertType', $alertType)
            ->orderBy('sa.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find by IP address
     */
    public function findByIpAddress(string $ipAddress): array
    {
        return $this->createQueryBuilder('sa')
            ->where('sa.ipAddress = :ipAddress')
            ->setParameter('ipAddress', $ipAddress)
            ->orderBy('sa.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Count by severity
     */
    public function countBySeverity(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->createQueryBuilder('sa')
            ->select('sa.severity, COUNT(sa.id) as count')
            ->where('sa.createdAt BETWEEN :from AND :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->groupBy('sa.severity')
            ->orderBy('count', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Count by type
     */
    public function countByType(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->createQueryBuilder('sa')
            ->select('sa.alertType, COUNT(sa.id) as count')
            ->where('sa.createdAt BETWEEN :from AND :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->groupBy('sa.alertType')
            ->orderBy('count', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get dashboard statistics
     */
    public function getDashboardStats(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $stats = [];

        // Total alerts
        $stats['total'] = (int) $this->createQueryBuilder('sa')
            ->select('COUNT(sa.id)')
            ->where('sa.createdAt BETWEEN :from AND :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getSingleScalarResult();

        // Critical alerts
        $stats['critical'] = (int) $this->createQueryBuilder('sa')
            ->select('COUNT(sa.id)')
            ->where('sa.createdAt BETWEEN :from AND :to')
            ->andWhere('sa.severity = :severity')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->setParameter('severity', SecurityAlert::SEVERITY_CRITICAL)
            ->getQuery()
            ->getSingleScalarResult();

        // High alerts
        $stats['high'] = (int) $this->createQueryBuilder('sa')
            ->select('COUNT(sa.id)')
            ->where('sa.createdAt BETWEEN :from AND :to')
            ->andWhere('sa.severity = :severity')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->setParameter('severity', SecurityAlert::SEVERITY_HIGH)
            ->getQuery()
            ->getSingleScalarResult();

        // Unacknowledged
        $stats['unacknowledged'] = (int) $this->createQueryBuilder('sa')
            ->select('COUNT(sa.id)')
            ->where('sa.createdAt BETWEEN :from AND :to')
            ->andWhere('sa.status = :status')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->setParameter('status', SecurityAlert::STATUS_NEW)
            ->getQuery()
            ->getSingleScalarResult();

        return $stats;
    }
}
