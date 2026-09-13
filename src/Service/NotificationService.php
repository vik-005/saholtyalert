<?php

namespace App\Service;

use App\Entity\Alert;
use App\Entity\Notification;
use App\Entity\User;
use App\Enum\UserRoleEnum;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Service de notification GEI.
 *
 * Déclencheurs complets (Partie H) :
 *  - Soumission d'alerte Agent     → notifyManagersOfMarket()   type 'info' ou 'urgence'
 *  - Validation Manager            → notify(Agent)              type 'validation'
 *  - Rejet Manager                 → notify(Agent)              type 'rejet'
 *  - Alerte critique (score ≥ 18)  → notifyUrgent(Managers)     type 'urgence', priorite=1
 *  - SLA proche (1h avant)         → SlaWatcherCommand          type 'sla_proche', priorite=1
 *  - SLA dépassé                   → SlaWatcherCommand          type 'urgence',   priorite=1
 *
 * Priorité visuelle (Partie H) :
 *  - priorite=0 → notification normale
 *  - priorite=1 → notification urgente (rouge, tête de liste dans le template)
 */
class NotificationService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * Crée une notification standard pour un destinataire unique.
     */
    public function notify(
        User $destinataire,
        string $contenu,
        string $type = 'info',
        ?Alert $alert = null,
        int $priorite = 0
    ): Notification {
        // Anti-duplication : ne pas créer deux fois la même notification en 10 min
        if ($this->findExistingRecent($destinataire, $type, $alert)) {
            // Retourne silencieusement une notification fictive non persistée
            $dummy = new Notification();
            $dummy->setDestinataire($destinataire)->setContenu($contenu)->setType($type);
            return $dummy;
        }

        $notif = new Notification();
        $notif->setDestinataire($destinataire);
        $notif->setContenu($contenu);
        $notif->setType($type);
        $notif->setAlert($alert);
        $notif->setPriorite($priorite);

        $this->em->persist($notif);
        $this->em->flush();

        return $notif;
    }

    /**
     * Crée une notification urgente (rouge, tête de liste) pour un destinataire unique.
     * Partie H — notifications avec priorité visuelle supérieure.
     */
    public function notifyUrgent(
        User $destinataire,
        string $contenu,
        string $type = 'urgence',
        ?Alert $alert = null
    ): Notification {
        return $this->notify($destinataire, $contenu, $type, $alert, priorite: 1);
    }

    /**
     * Notifie tous les Managers (PFT + SAHOLTY) responsables d'un marché.
     * - Si score critique (type='urgence') → priorite=1 (rouge, tête de liste)
     * - Sinon → priorite=0 (notification normale)
     * Anti-duplication de 10 minutes inclus.
     */
    public function notifyManagersOfMarket(
        \App\Entity\Market $market,
        string $contenu,
        string $type = 'warning',
        ?Alert $alert = null
    ): void {
        $priorite = in_array($type, ['urgence', 'sla_proche'], true) ? 1 : 0;

        $managers = $this->em->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->where('u.role IN (:roles)')
            ->setParameter('roles', [UserRoleEnum::PFT->value, UserRoleEnum::SAHOLTY->value])
            ->getQuery()
            ->getResult();

        $notifications = [];
        foreach ($managers as $manager) {
            /** @var User $manager */
            $isSaholty = $manager->getRole() === UserRoleEnum::SAHOLTY;
            $inMarket  = in_array($market, $manager->getAllManagedMarkets(), true);

            if (!$isSaholty && !$inMarket) {
                continue;
            }

            if ($this->findExistingRecent($manager, $type, $alert)) {
                continue;
            }

            $notif = new Notification();
            $notif->setDestinataire($manager);
            $notif->setContenu($contenu);
            $notif->setType($type);
            $notif->setAlert($alert);
            $notif->setPriorite($priorite);
            $notifications[] = $notif;
            $this->em->persist($notif);
        }

        if (!empty($notifications)) {
            $this->em->flush();
        }
    }

    /**
     * Crée une notification urgente pour tous les Managers d'un marché.
     * Raccourci pour les cas critiques (score ≥ 18, SLA dépassé).
     */
    public function notifyUrgentManagersOfMarket(
        \App\Entity\Market $market,
        string $contenu,
        ?Alert $alert = null
    ): void {
        $this->notifyManagersOfMarket($market, $contenu, 'urgence', $alert);
    }

    /**
     * Vérifie qu'une notification identique n'a pas été envoyée dans les 10 dernières minutes.
     * Évite la duplication lors des recalculs ou crons fréquents.
     */
    public function findExistingRecent(User $destinataire, string $type, ?Alert $alert): ?Notification
    {
        if ($alert !== null && $alert->getId() === null) {
            return null;
        }

        return $this->em->getRepository(Notification::class)
            ->findExistingRecent($destinataire, $type, $alert);
    }
}
