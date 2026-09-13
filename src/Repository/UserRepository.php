<?php

namespace App\Repository;

use App\Entity\Market;
use App\Entity\User;
use App\Enum\UserRoleEnum;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();
    }

    /**
     * Retourne tous les utilisateurs actifs ayant le rôle donné.
     * Utilisé par AlertEscalationHandler pour cibler les destinataires des notifications.
     */
    public function findByRole(UserRoleEnum $role): array
    {
        return $this->createQueryBuilder('u')
            ->where('u.role = :role')
            ->andWhere('u.actif = true')
            ->setParameter('role', $role->value)
            ->orderBy('u.nom', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
      * Retourne les PFT (Managers) actifs responsables d'un marché donné.
      * Utilisé pour cibler les notifications d'escalade au bon Manager.
      */
    public function findByRoleAndMarket(UserRoleEnum $role, Market $market): array
    {
        return $this->createQueryBuilder('u')
            ->leftJoin('u.markets', 'managedMarket')
            ->addSelect('managedMarket')
            ->where('u.role = :role')
            ->andWhere('u.actif = true')
            ->andWhere('managedMarket = :market OR u.market = :market')
            ->setParameter('role', $role->value)
            ->setParameter('market', $market)
            ->orderBy('u.nom', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @param Market[] $markets
     * @return User[]
     */
    public function findByRoleAndMarkets(UserRoleEnum $role, array $markets): array
    {
        if (empty($markets)) {
            return [];
        }

        return $this->createQueryBuilder('u')
            ->leftJoin('u.markets', 'managedMarket')
            ->addSelect('managedMarket')
            ->where('u.role = :role')
            ->andWhere('u.actif = true')
            ->andWhere('managedMarket IN (:markets) OR u.market IN (:markets)')
            ->setParameter('role', $role->value)
            ->setParameter('markets', $markets)
            ->orderBy('u.nom', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @param Market[] $markets
     * @return User[]
     */
    public function findAgentsByMarkets(array $markets): array
    {
        if (empty($markets)) {
            return [];
        }

        return $this->createQueryBuilder('u')
            ->where('u.role = :role')
            ->andWhere('u.market IN (:markets)')
            ->setParameter('role', UserRoleEnum::EMETTEUR_TERRAIN->value)
            ->setParameter('markets', $markets)
            ->orderBy('u.nom', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
