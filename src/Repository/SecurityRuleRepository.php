<?php

namespace App\Repository;

use App\Entity\SecurityRule;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SecurityRule>
 */
class SecurityRuleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SecurityRule::class);
    }

    /**
     * Find enabled rules
     */
    public function findEnabled(): array
    {
        return $this->createQueryBuilder('sr')
            ->where('sr.isEnabled = true')
            ->orderBy('sr.ruleType', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find enabled rules by type
     */
    public function findEnabledByType(string $ruleType): array
    {
        return $this->createQueryBuilder('sr')
            ->where('sr.isEnabled = true')
            ->andWhere('sr.ruleType = :ruleType')
            ->setParameter('ruleType', $ruleType)
            ->orderBy('sr.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find all by type
     */
    public function findByType(string $ruleType): array
    {
        return $this->createQueryBuilder('sr')
            ->where('sr.ruleType = :ruleType')
            ->setParameter('ruleType', $ruleType)
            ->orderBy('sr.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find rules by severity
     */
    public function findBySeverity(string $severity): array
    {
        return $this->createQueryBuilder('sr')
            ->where('sr.alertSeverity = :severity')
            ->andWhere('sr.isEnabled = true')
            ->setParameter('severity', $severity)
            ->orderBy('sr.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
