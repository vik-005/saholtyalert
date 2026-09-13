<?php

namespace App\Repository;

use App\Entity\ListeReferenceValeur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ListeReferenceValeurRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ListeReferenceValeur::class);
    }

    /**
     * @return ListeReferenceValeur[] Retourne les valeurs actives d'un type donné, triées par ordre d'affichage
     */
    public function findActivesByType(string $typeListe): array
    {
        return $this->createQueryBuilder('l')
            ->where('l.typeListe = :type')
            ->andWhere('l.actif = true')
            ->setParameter('type', $typeListe)
            ->orderBy('l.ordreAffichage', 'ASC')
            ->addOrderBy('l.libelle', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return ListeReferenceValeur[] Retourne toutes les valeurs d'un type donné (actives et inactives)
     */
    public function findAllByType(string $typeListe): array
    {
        return $this->createQueryBuilder('l')
            ->where('l.typeListe = :type')
            ->setParameter('type', $typeListe)
            ->orderBy('l.actif', 'DESC')
            ->addOrderBy('l.ordreAffichage', 'ASC')
            ->addOrderBy('l.libelle', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return array<string, string[]> Tableau groupé par type_liste => [id => libelle] pour les valeurs actives
     */
    public function findActivesGroupedByType(): array
    {
        $results = $this->createQueryBuilder('l')
            ->where('l.actif = true')
            ->orderBy('l.typeListe', 'ASC')
            ->addOrderBy('l.ordreAffichage', 'ASC')
            ->getQuery()
            ->getResult();

        $grouped = [];
        foreach ($results as $item) {
            $type = $item->getTypeListe();
            if (!isset($grouped[$type])) {
                $grouped[$type] = [];
            }
            $grouped[$type][$item->getId()] = $item->getLibelle();
        }

        return $grouped;
    }
}
