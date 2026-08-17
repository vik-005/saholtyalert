<?php

namespace App\Repository;

use App\Entity\RuleConfig;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RuleConfig>
 */
class RuleConfigRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RuleConfig::class);
    }

    /**
     * Retourne toutes les configurations actives sous forme de tableau clé => valeur.
     * Utilisé par EscalationRuleEngine et ScoreCalculatorService.
     */
    public function findAllAsMap(): array
    {
        $configs = $this->createQueryBuilder('r')
            ->where('r.actif = true')
            ->getQuery()
            ->getResult();

        $map = [];
        foreach ($configs as $config) {
            /** @var RuleConfig $config */
            $map[$config->getCle()] = $config->getValeur();
        }
        return $map;
    }

    /**
     * Retourne la valeur entière d'une clé de configuration.
     * Retourne $default si la clé n'existe pas ou n'est pas active.
     */
    public function getInt(string $cle, int $default = 0): int
    {
        $config = $this->findOneBy(['cle' => $cle, 'actif' => true]);
        return $config ? $config->getValeurInt() : $default;
    }
}
