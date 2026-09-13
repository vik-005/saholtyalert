<?php

namespace App\Repository;

use App\Entity\Alert;
use App\Entity\Notification;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Notification>
 */
class NotificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Notification::class);
    }

    /**
     * Notifications non lues d'un utilisateur.
     * Tri : urgentes en tête (priorite DESC), puis par date décroissante (Partie H).
     */
    public function findUnreadForUser(User $user, int $limit = 20): array
    {
        return $this->createQueryBuilder('n')
            ->leftJoin('n.alert', 'a')
            ->addSelect('a')
            ->where('n.destinataire = :user')
            ->andWhere('n.lu = false')
            ->setParameter('user', $user)
            ->orderBy('n.priorite', 'DESC')
            ->addOrderBy('n.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Toutes les notifications d'un utilisateur (lues + non lues).
     * Tri : urgentes en tête (priorite DESC), puis par date décroissante (Partie H).
     */
    public function findAllForUser(User $user, int $limit = 50): array
    {
        return $this->createQueryBuilder('n')
            ->leftJoin('n.alert', 'a')
            ->addSelect('a')
            ->where('n.destinataire = :user')
            ->setParameter('user', $user)
            ->orderBy('n.priorite', 'DESC')
            ->addOrderBy('n.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Compteur exact de non-lues pour le badge de la cloche.
     * Partie D point 8 — résultat utilisé par l'API /api/notifications/count.
     */
    public function countUnreadForUser(User $user): int
    {
        return (int) $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->where('n.destinataire = :user')
            ->andWhere('n.lu = false')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
      * Vérifie si une notification identique (même destinataire + même alerte + même type)
      * a déjà été créée dans les 10 dernières minutes.
      * Partie D point 10 — anti-duplication.
      */
    public function findExistingRecent(User $destinataire, string $type, ?Alert $alert): ?Notification
    {
        // Si l'alerte n'est pas encore persistée en BDD (ID null), elle ne peut pas avoir de notifications existantes
        if ($alert !== null && $alert->getId() === null) {
            return null;
        }

        $depuis = new \DateTimeImmutable('-10 minutes');

        $qb = $this->createQueryBuilder('n')
            ->where('n.destinataire = :user')
            ->andWhere('n.type = :type')
            ->andWhere('n.createdAt >= :depuis')
            ->setParameter('user', $destinataire)
            ->setParameter('type', $type)
            ->setParameter('depuis', $depuis)
            ->setMaxResults(1);

        if ($alert !== null) {
            $qb->andWhere('n.alert = :alert')->setParameter('alert', $alert);
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    /**
     * Retourne toutes les notifications récentes pour une liste d'utilisateurs et de types.
     * Utilisé pour pré-charger les notifications et éviter les requêtes N+1 dans les commands.
     */
    public function findRecentByUsersAndTypes(array $userIds, array $types, \DateTimeImmutable $since): array
    {
        if (empty($userIds) || empty($types)) {
            return [];
        }

        return $this->createQueryBuilder('n')
            ->where('n.destinataire IN (:userIds)')
            ->andWhere('n.type IN (:types)')
            ->andWhere('n.createdAt >= :since')
            ->setParameter('userIds', $userIds)
            ->setParameter('types', $types)
            ->setParameter('since', $since)
            ->getQuery()
            ->getResult();
    }

    /**
     * Notifications urgentes non lues (priorite=1 — badge rouge distinct topbar, Partie H).
     */
    public function countUrgentUnread(User $user): int
    {
        return (int) $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->where('n.destinataire = :user')
            ->andWhere('n.lu = false')
            ->andWhere('n.priorite >= 1')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
