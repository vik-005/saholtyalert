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

    /**
     * Indicateurs opérationnels par compte, en distinguant les alertes émises
     * des décisions de validation prises par les profils Manager.
     *
     * @return array<int, array<string, int|float|string|null>>
     */
    public function getOperationalPerformanceStats(): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(<<<'SQL'
            SELECT
                u.id,
                u.prenom,
                u.nom,
                u.photo_profil AS photoProfil,
                u.role,
                u.actif,
                m.nom AS agency,
                COALESCE(submitted.total, 0) AS submitted,
                COALESCE(submitted.validated, 0) AS validated,
                COALESCE(submitted.rejected, 0) AS rejected,
                COALESCE(submitted.pending, 0) AS pending,
                COALESCE(submitted.transmitted, 0) AS transmitted,
                COALESCE(submitted.averageScore, 0) AS averageScore,
                submitted.averageDelayHours,
                COALESCE(review.reviewed, 0) AS reviewed,
                COALESCE(review.validated, 0) AS managerValidated,
                COALESCE(review.rejected, 0) AS managerRejected
            FROM `user` u
            LEFT JOIN market m ON m.id = u.market_id
            LEFT JOIN (
                SELECT
                    a.emetteur_id AS userId,
                    COUNT(a.id) AS total,
                    SUM(CASE WHEN a.statut = 'validee' THEN 1 ELSE 0 END) AS validated,
                    SUM(CASE WHEN a.statut = 'a_completer' THEN 1 ELSE 0 END) AS rejected,
                    SUM(CASE WHEN a.statut IN ('nouveau', 'ouvert', 'en_cours', 'en_cours_analyse', 'a_investiguer', 'ouvert_prioritaire', 'a_valider_saholty') THEN 1 ELSE 0 END) AS pending,
                    SUM(CASE WHEN a.statut = 'transmis' THEN 1 ELSE 0 END) AS transmitted,
                    AVG(COALESCE(a.score_surcharge, a.score_gei)) AS averageScore,
                    AVG(CASE WHEN a.date_validation IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, a.created_at, a.date_validation) / 60 END) AS averageDelayHours
                FROM alert a
                WHERE a.deleted_at IS NULL
                GROUP BY a.emetteur_id
            ) submitted ON submitted.userId = u.id
            LEFT JOIN (
                SELECT
                    a.validated_by_id AS userId,
                    SUM(CASE WHEN a.statut IN ('validee', 'a_completer') THEN 1 ELSE 0 END) AS reviewed,
                    SUM(CASE WHEN a.statut = 'validee' THEN 1 ELSE 0 END) AS validated,
                    SUM(CASE WHEN a.statut = 'a_completer' THEN 1 ELSE 0 END) AS rejected
                FROM alert a
                WHERE a.deleted_at IS NULL AND a.validated_by_id IS NOT NULL
                GROUP BY a.validated_by_id
            ) review ON review.userId = u.id
            ORDER BY m.nom ASC, u.role ASC, u.nom ASC, u.prenom ASC
            SQL);

        return array_map(static function (array $row): array {
            foreach (['id', 'submitted', 'validated', 'rejected', 'pending', 'transmitted', 'reviewed', 'managerValidated', 'managerRejected'] as $key) {
                $row[$key] = (int) $row[$key];
            }
            $row['averageScore'] = round((float) $row['averageScore'], 1);
            $row['averageDelayHours'] = $row['averageDelayHours'] !== null ? round((float) $row['averageDelayHours'], 1) : null;
            $row['actif'] = (bool) $row['actif'];

            return $row;
        }, $rows);
    }
}
