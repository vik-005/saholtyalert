<?php

namespace App\Repository;

use App\Entity\IpAddressReputation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<IpAddressReputation>
 */
class IpAddressReputationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IpAddressReputation::class);
    }

    /**
     * Find by IP address or create placeholder
     */
    public function findOrCreate(string $ipAddress): IpAddressReputation
    {
        $reputation = $this->findOneBy(['ipAddress' => $ipAddress]);
        
        if ($reputation === null) {
            $reputation = new IpAddressReputation();
            $reputation->setIpAddress($ipAddress);
            $this->getEntityManager()->persist($reputation);
            $this->getEntityManager()->flush();
        }

        return $reputation;
    }

    /**
     * Find by IP address
     */
    public function findByIpAddress(string $ipAddress): ?IpAddressReputation
    {
        return $this->findOneBy(['ipAddress' => $ipAddress]);
    }

    /**
     * Find expired records that need refresh
     */
    public function findExpiredRecords(int $limit = 100): array
    {
        $threshold = new \DateTimeImmutable('-' . IpAddressReputation::TTL_CACHE . ' seconds');
        
        return $this->createQueryBuilder('iar')
            ->where('iar.lastCheckedAt < :threshold')
            ->setParameter('threshold', $threshold)
            ->orderBy('iar.lastCheckedAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find high-risk IPs
     */
    public function findHighRiskIps(int $minScore = 50): array
    {
        return $this->createQueryBuilder('iar')
            ->where('iar.riskScore >= :minScore')
            ->setParameter('minScore', $minScore)
            ->orderBy('iar.riskScore', 'DESC')
            ->setMaxResults(100)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find anonymized IPs (VPN, Proxy, Tor)
     */
    public function findAnonymizedIps(): array
    {
        return $this->createQueryBuilder('iar')
            ->where('iar.isVpn = true OR iar.isProxy = true OR iar.isTor = true')
            ->orderBy('iar.updatedAt', 'DESC')
            ->setMaxResults(500)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find IPs from specific country
     */
    public function findByCountry(string $countryCode): array
    {
        return $this->createQueryBuilder('iar')
            ->where('iar.countryCode = :countryCode')
            ->setParameter('countryCode', $countryCode)
            ->orderBy('iar.riskScore', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
