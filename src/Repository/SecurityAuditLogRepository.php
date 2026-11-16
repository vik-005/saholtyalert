<?php

namespace App\Repository;

use App\Entity\SecurityAuditLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SecurityAuditLog>
 */
class SecurityAuditLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SecurityAuditLog::class);
    }

    /**
     * Find recent audit logs with optional filters.
     */
    public function findRecent(int $limit = 100, int $offset = 0, array $filters = []): array
    {
        $qb = $this->createQueryBuilder('sal')
            ->orderBy('sal.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        if (isset($filters['eventType'])) {
            $qb->andWhere('sal.eventType = :eventType')
               ->setParameter('eventType', $filters['eventType']);
        }

        if (isset($filters['ipAddress'])) {
            $qb->andWhere('sal.ipAddress = :ipAddress')
               ->setParameter('ipAddress', $filters['ipAddress']);
        }

        if (isset($filters['userId'])) {
            $qb->andWhere('sal.user = :userId')
               ->setParameter('userId', $filters['userId']);
        }

        if (isset($filters['riskScoreMin'])) {
            $qb->andWhere('sal.riskScore >= :riskScoreMin')
               ->setParameter('riskScoreMin', $filters['riskScoreMin']);
        }

        if (isset($filters['dateFrom'])) {
            $qb->andWhere('sal.createdAt >= :dateFrom')
               ->setParameter('dateFrom', $filters['dateFrom']);
        }

        if (isset($filters['dateTo'])) {
            $qb->andWhere('sal.createdAt <= :dateTo')
               ->setParameter('dateTo', $filters['dateTo']);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Count logs by event type between two dates.
     */
    public function countByEventType(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        try {
            return $this->createQueryBuilder('sal')
                ->select('sal.eventType, COUNT(sal.id) as count')
                ->where('sal.createdAt BETWEEN :from AND :to')
                ->setParameter('from', $from)
                ->setParameter('to', $to)
                ->groupBy('sal.eventType')
                ->orderBy('count', 'DESC')
                ->getQuery()
                ->getResult();
        } catch (TableNotFoundException|\Doctrine\DBAL\Driver\PDO\Exception|\Doctrine\DBAL\Exception) {
            return [];
        }
    }

    /**
     * Count logs by risk level between two dates.
     */
    public function countByRiskLevel(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        try {
            return $this->createQueryBuilder('sal')
                ->select('sal.riskLevel, COUNT(sal.id) as count')
                ->where('sal.createdAt BETWEEN :from AND :to')
                ->setParameter('from', $from)
                ->setParameter('to', $to)
                ->groupBy('sal.riskLevel')
                ->orderBy('count', 'DESC')
                ->getQuery()
                ->getResult();
        } catch (TableNotFoundException|\Doctrine\DBAL\Driver\PDO\Exception|\Doctrine\DBAL\Exception) {
            return [];
        }
    }

    /**
     * Find logs by IP address.
     */
    public function findByIpAddress(string $ipAddress, int $limit = 100): array
    {
        return $this->createQueryBuilder('sal')
            ->where('sal.ipAddress = :ipAddress')
            ->setParameter('ipAddress', $ipAddress)
            ->orderBy('sal.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find failed login attempts for a specific user since a date.
     */
    public function findFailedLoginAttemptsForUser(int $userId, \DateTimeImmutable $since): array
    {
        return $this->createQueryBuilder('sal')
            ->where('sal.eventType = :eventType')
            ->andWhere('sal.user = :userId')
            ->andWhere('sal.createdAt >= :since')
            ->setParameter('eventType', SecurityAuditLog::EVENT_LOGIN_FAILED)
            ->setParameter('userId', $userId)
            ->setParameter('since', $since)
            ->orderBy('sal.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Dashboard KPIs: total, uniqueIps, avgRiskScore, highRiskEvents.
     * Uses 4 separate scalar queries — avoids a heavy single GROUP BY.
     */
    public function getDashboardStats(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        try {
            $stats = [];

            $stats['total'] = (int) $this->createQueryBuilder('sal')
                ->select('COUNT(sal.id)')
                ->where('sal.createdAt BETWEEN :from AND :to')
                ->setParameter('from', $from)
                ->setParameter('to', $to)
                ->getQuery()
                ->getSingleScalarResult();

            $stats['uniqueIps'] = (int) $this->createQueryBuilder('sal')
                ->select('COUNT(DISTINCT sal.ipAddress)')
                ->where('sal.createdAt BETWEEN :from AND :to')
                ->setParameter('from', $from)
                ->setParameter('to', $to)
                ->getQuery()
                ->getSingleScalarResult();

            $stats['avgRiskScore'] = (float) ($this->createQueryBuilder('sal')
                ->select('AVG(sal.riskScore)')
                ->where('sal.createdAt BETWEEN :from AND :to')
                ->setParameter('from', $from)
                ->setParameter('to', $to)
                ->getQuery()
                ->getSingleScalarResult() ?? 0);

            $stats['highRiskEvents'] = (int) $this->createQueryBuilder('sal')
                ->select('COUNT(sal.id)')
                ->where('sal.createdAt BETWEEN :from AND :to')
                ->andWhere('sal.riskScore >= 80')
                ->setParameter('from', $from)
                ->setParameter('to', $to)
                ->getQuery()
                ->getSingleScalarResult();

            return $stats;
        } catch (TableNotFoundException|\Doctrine\DBAL\Driver\PDO\Exception|\Doctrine\DBAL\Exception) {
            return ['total' => 0, 'uniqueIps' => 0, 'avgRiskScore' => 0.0, 'highRiskEvents' => 0];
        }
    }

    /**
     * Find suspicious IPs ordered by average risk score.
     */
    public function findSuspiciousIps(int $minRiskScore = 60, ?\DateTimeImmutable $from = null): array
    {
        $qb = $this->createQueryBuilder('sal')
            ->select('sal.ipAddress, COUNT(sal.id) as count, AVG(sal.riskScore) as avgRisk')
            ->where('sal.riskScore >= :minRisk')
            ->setParameter('minRisk', $minRiskScore)
            ->groupBy('sal.ipAddress')
            ->orderBy('avgRisk', 'DESC');

        if ($from !== null) {
            $qb->andWhere('sal.createdAt >= :from')
               ->setParameter('from', $from);
        }

        return $qb->setMaxResults(100)->getQuery()->getResult();
    }

    /**
     * Route statistics grouped and aggregated — 1 query.
     *
     * @return array<string, array{total:int, errors:int, forbidden:int, avgRisk:float}>
     */
    public function getRouteStats(int $days = 7): array
    {
        $since = new \DateTimeImmutable("-{$days} days");

        $rows = $this->createQueryBuilder('sal')
            ->select(
                'sal.routeName AS route',
                'sal.httpStatusCode AS code',
                'COUNT(sal.id) AS cnt',
                'AVG(sal.riskScore) AS avgRisk'
            )
            ->where('sal.createdAt >= :since')
            ->setParameter('since', $since)
            ->groupBy('sal.routeName, sal.httpStatusCode')
            ->orderBy('cnt', 'DESC')
            ->setMaxResults(500)
            ->getQuery()
            ->getResult();

        $routes = [];
        foreach ($rows as $row) {
            $name = $row['route'] ?? 'unknown';
            $routes[$name] ??= ['total' => 0, 'errors' => 0, 'forbidden' => 0, 'avgRisk' => 0.0, '_cnt' => 0];

            $prevTotal = $routes[$name]['_cnt'];
            $newTotal  = $prevTotal + (int) $row['cnt'];

            // Moyenne pondérée pour avgRisk
            $routes[$name]['avgRisk'] = $newTotal > 0
                ? (($routes[$name]['avgRisk'] * $prevTotal) + ((float) $row['avgRisk'] * (int) $row['cnt'])) / $newTotal
                : 0.0;

            $routes[$name]['total'] += (int) $row['cnt'];
            $routes[$name]['_cnt']   = $newTotal;

            if (in_array((int) $row['code'], [404, 500, 502, 503], true)) {
                $routes[$name]['errors'] += (int) $row['cnt'];
            }
            if ((int) $row['code'] === 403) {
                $routes[$name]['forbidden'] += (int) $row['cnt'];
            }
        }

        foreach ($routes as &$r) {
            $r['avgRisk'] = round($r['avgRisk'], 1);
            unset($r['_cnt']);
        }
        unset($r);

        uasort($routes, static fn($a, $b) => $b['total'] <=> $a['total']);

        return $routes;
    }
}
