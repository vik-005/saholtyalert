<?php

namespace App\Repository;

use App\Entity\IpBlocklist;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<IpBlocklist>
 */
class IpBlocklistRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IpBlocklist::class);
    }

    /**
     * Check if IP is blocked
     */
    public function isIpBlocked(string $ipAddress): bool
    {
        $entry = $this->findOneBy([
            'ipAddress' => $ipAddress,
            'isActive' => true,
            'isWhitelist' => false,
        ]);

        if ($entry === null) {
            return false;
        }

        // Check if expired
        if ($entry->isExpired()) {
            $entry->setIsActive(false);
            $this->getEntityManager()->flush();
            return false;
        }

        return true;
    }

    /**
     * Check if IP is whitelisted
     */
    public function isIpWhitelisted(string $ipAddress): bool
    {
        $entry = $this->findOneBy([
            'ipAddress' => $ipAddress,
            'isActive' => true,
            'isWhitelist' => true,
        ]);

        if ($entry === null) {
            return false;
        }

        // Check if expired
        if ($entry->isExpired()) {
            $entry->setIsActive(false);
            $this->getEntityManager()->flush();
            return false;
        }

        return true;
    }

    /**
     * Find IP entry (blacklist or whitelist)
     */
    public function findByIpAddress(string $ipAddress): ?IpBlocklist
    {
        return $this->findOneBy(['ipAddress' => $ipAddress]);
    }

    /**
     * Find active blocks
     */
    public function findActiveBlocks(): array
    {
        return $this->createQueryBuilder('ib')
            ->where('ib.isActive = true')
            ->andWhere('ib.isWhitelist = false')
            ->andWhere('(ib.expiresAt IS NULL OR ib.expiresAt > CURRENT_TIMESTAMP())')
            ->orderBy('ib.blockedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find active whitelist entries
     */
    public function findActiveWhitelist(): array
    {
        return $this->createQueryBuilder('ib')
            ->where('ib.isActive = true')
            ->andWhere('ib.isWhitelist = true')
            ->orderBy('ib.blockedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find recently blocked IPs
     */
    public function findRecentlyBlocked(int $days = 7): array
    {
        $since = new \DateTimeImmutable('-' . $days . ' days');
        
        return $this->createQueryBuilder('ib')
            ->where('ib.isActive = true')
            ->andWhere('ib.isWhitelist = false')
            ->andWhere('ib.blockedAt >= :since')
            ->setParameter('since', $since)
            ->orderBy('ib.blockedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find expired entries that should be cleaned up
     */
    public function findExpiredEntries(): array
    {
        return $this->createQueryBuilder('ib')
            ->where('ib.isActive = true')
            ->andWhere('ib.expiresAt IS NOT NULL')
            ->andWhere('ib.expiresAt <= CURRENT_TIMESTAMP()')
            ->getQuery()
            ->getResult();
    }

    /**
     * Count active blocks
     */
    public function countActiveBlocks(): int
    {
        return (int) $this->createQueryBuilder('ib')
            ->select('COUNT(ib.id)')
            ->where('ib.isActive = true')
            ->andWhere('ib.isWhitelist = false')
            ->andWhere('(ib.expiresAt IS NULL OR ib.expiresAt > CURRENT_TIMESTAMP())')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Find entries by reason
     */
    public function findByReason(string $reason): array
    {
        return $this->createQueryBuilder('ib')
            ->where('ib.reason = :reason')
            ->andWhere('ib.isActive = true')
            ->setParameter('reason', $reason)
            ->orderBy('ib.blockedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
