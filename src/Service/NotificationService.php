<?php

namespace App\Service;

use App\Entity\Alert;
use App\Entity\Notification;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

class NotificationService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function notify(User $destinataire, string $contenu, string $type = 'info', ?Alert $alert = null): Notification
    {
        $notif = new Notification();
        $notif->setDestinataire($destinataire);
        $notif->setContenu($contenu);
        $notif->setType($type);
        $notif->setAlert($alert);

        $this->em->persist($notif);
        $this->em->flush();

        return $notif;
    }

    public function notifyManagersOfMarket(array $markets, string $contenu, string $type = 'warning', ?Alert $alert = null): void
    {
        $qb = $this->em->createQueryBuilder();
        $qb->select('u')
            ->from(User::class, 'u')
            ->where('u.role IN (:roles)')
            ->setParameter('roles', [\App\Enum\UserRoleEnum::PFT, \App\Enum\UserRoleEnum::SAHOLTY]);

        $managers = $qb->getQuery()->getResult();

        foreach ($managers as $manager) {
            /** @var User $manager */
            $this->notify($manager, $contenu, $type, $alert);
        }
    }
}
