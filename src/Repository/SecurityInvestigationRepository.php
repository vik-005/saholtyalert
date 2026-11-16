<?php

namespace App\Repository;

use App\Entity\SecurityInvestigation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SecurityInvestigation>
 */
class SecurityInvestigationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SecurityInvestigation::class);
    }

    /**
     * Find open investigations
     */
    public function findOpen(): array
    {
        return $this->createQueryBuilder('si')
            ->where('si.status != :closed')
            ->setParameter('closed', SecurityInvestigation::STATUS_CLOSED)
            ->orderBy('si.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find by status
     */
    public function findByStatus(string $status): array
    {
        return $this->createQueryBuilder('si')
            ->where('si.status = :status')
            ->setParameter('status', $status)
            ->orderBy('si.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find by IP address
     */
    public function findByIpAddress(string $ipAddress): array
    {
        return $this->createQueryBuilder('si')
            ->where('si.ipAddress = :ipAddress')
            ->setParameter('ipAddress', $ipAddress)
            ->orderBy('si.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find recent investigations
     */
    public function findRecent(int $limit = 50): array
    {
        return $this->createQueryBuilder('si')
            ->orderBy('si.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Count by severity
     */
    public function countBySeverity(): array
    {
        return $this->createQueryBuilder('si')
            ->select('si.severity, COUNT(si.id) as count')
            ->where('si.status != :closed')
            ->setParameter('closed', SecurityInvestigation::STATUS_CLOSED)
            ->groupBy('si.severity')
            ->getQuery()
            ->getResult();
    }

    /**
     * Count by status
     */
    public function countByStatus(): array
    {
        return $this->createQueryBuilder('si')
            ->select('si.status, COUNT(si.id) as count')
            ->groupBy('si.status')
            ->orderBy('count', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
