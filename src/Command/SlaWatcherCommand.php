<?php

namespace App\Command;

use App\Entity\Notification;
use App\Repository\NotificationRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Commande de surveillance des SLA 72h — à exécuter via cron toutes les 15 min.
 *
 * Cron recommandé :
 *   *\/15 * * * * php /chemin/bin/console app:sla:watch >> /var/log/gei-sla.log 2>&1
 *
 * Partie D point 3 : SLA 72h proche (1h avant échéance) → notification Manager.
 * Partie D point 4 : SLA dépassé → notification Manager + log.
 */
#[AsCommand(
    name: 'app:sla:watch',
    description: 'Surveille les cas urgence 72h et notifie les Managers avant et après échéance',
)]
class SlaWatcherCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface  $em,
        private readonly NotificationRepository  $notifRepo,
        private readonly UserRepository          $userRepo,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $now = new \DateTime();

        // Récupérer tous les cas urgence actifs
        $cases = $this->em->createQueryBuilder()
            ->select('c', 'a', 'm')
            ->from('App\Entity\Urgence72hCase', 'c')
            ->join('c.alert', 'a')
            ->join('a.market', 'm')
            ->where('c.statutCase = :actif')
            ->setParameter('actif', 'active')
            ->getQuery()
            ->getResult();

        $notified1h = 0;
        $notifiedDepass = 0;

        foreach ($cases as $case) {
            $alert = $case->getAlert();
            $heuresEcoulees = $case->getHeuresEcoulees();
            $heuresRestantes = 72 - $heuresEcoulees;

            // Managers du marché concerné
            $managers = $this->userRepo->findByRoleAndMarket(
                \App\Enum\UserRoleEnum::PFT,
                $alert->getMarket()
            );

            // ── Alerte 1h avant échéance (entre 71h et 72h) ────────────────
            if ($heuresRestantes > 0 && $heuresRestantes <= 1) {
                foreach ($managers as $manager) {
                    // Anti-doublon : pas de re-notification si déjà envoyée dans les 30 min
                    $existing = $this->notifRepo->findExistingRecent($manager, 'sla_proche', $alert);
                    if (!$existing) {
                        $notif = new Notification();
                        $notif->setDestinataire($manager);
                        $notif->setAlert($alert);
                        $notif->setType('sla_proche');
                        $notif->setContenu(sprintf(
                            '⏰ SLA CRITIQUE — Alerte %s : il reste %.1f heure(s) avant expiration de la procédure urgence 72h.',
                            $alert->getCodeGei() ?? '#' . $alert->getId(),
                            $heuresRestantes
                        ));
                        $this->em->persist($notif);
                        $notified1h++;
                    }
                }
            }

            // ── SLA dépassé (> 72h et toujours actif) ──────────────────────
            if ($heuresEcoulees > 72) {
                foreach ($managers as $manager) {
                    $existing = $this->notifRepo->findExistingRecent($manager, 'sla_depasse', $alert);
                    if (!$existing) {
                        $notif = new Notification();
                        $notif->setDestinataire($manager);
                        $notif->setAlert($alert);
                        $notif->setType('urgence'); // type urgence pour badge rouge
                        $notif->setContenu(sprintf(
                            '🔴 SLA DÉPASSÉ — Alerte %s : procédure urgence 72h dépassée de %.1f heure(s). Action immédiate requise.',
                            $alert->getCodeGei() ?? '#' . $alert->getId(),
                            $heuresEcoulees - 72
                        ));
                        $this->em->persist($notif);
                        $notifiedDepass++;
                    }
                }
            }
        }

        $this->em->flush();

        $io->success(sprintf(
            'SLA Watch terminé : %d cas actifs, %d notif. "1h avant", %d notif. "dépassé".',
            count($cases),
            $notified1h,
            $notifiedDepass
        ));

        return Command::SUCCESS;
    }
}
