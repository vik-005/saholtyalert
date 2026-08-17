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
     * Notifications non lues d'un utilisateur, triées par date décroissante.
     * Utilisé par la cloche topbar et l'API polling.
     */
    public function findUnreadForUser(User $user, int $limit = 20): array
    {
        return $this->createQueryBuilder('n')
            ->leftJoin('n.alert', 'a')
            ->addSelect('a')
            ->where('n.destinataire = :user')
            ->andWhere('n.lu = false')
            ->setParameter('user', $user)
            ->orderBy('n.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Toutes les notifications d'un utilisateur (lues + non lues).
     * Utilisé par la page /notifications.
     */
    public function findAllForUser(User $user, int $limit = 50): array
    {
        return $this->createQueryBuilder('n')
            ->leftJoin('n.alert', 'a')
            ->addSelect('a')
            ->where('n.destinataire = :user')
            ->setParameter('user', $user)
            ->orderBy('n.createdAt', 'DESC')
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
     * Notifications urgentes non lues (type = 'urgence' ou 'validation').
     * Utilisées pour le badge rouge distinct dans la topbar.
     */
    public function countUrgentUnread(User $user): int
    {
        return (int) $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->where('n.destinataire = :user')
            ->andWhere('n.lu = false')
            ->andWhere('n.type IN (:types)')
            ->setParameter('user', $user)
            ->setParameter('types', ['urgence', 'validation', 'tache'])
            ->getQuery()
            ->getSingleScalarResult();
    }
}
