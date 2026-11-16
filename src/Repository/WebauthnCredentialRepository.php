<?php

namespace App\Repository;

use App\Entity\User;
use App\Entity\WebauthnCredential;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WebauthnCredential>
 */
class WebauthnCredentialRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WebauthnCredential::class);
    }

    /**
     * @return WebauthnCredential[]
     */
    public function findByUser(User $user): array
    {
        return $this->createQueryBuilder('wc')
            ->where('wc.user = :user')
            ->setParameter('user', $user)
            ->orderBy('wc.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByCredentialId(string $credentialId): ?WebauthnCredential
    {
        return $this->createQueryBuilder('wc')
            ->where('wc.credentialId = :credentialId')
            ->setParameter('credentialId', $credentialId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
