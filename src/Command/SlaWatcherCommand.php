<?php

namespace App\Command;

use App\Entity\Market;
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
        set_time_limit(300);

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

        if (empty($cases)) {
            $io->success('SLA Watch terminé : 0 cas actif, aucune notification.');
            return Command::SUCCESS;
        }

        // Collecter tous les marchés uniques
        $marketIds = [];
        foreach ($cases as $case) {
            $market = $case->getAlert()->getMarket();
            if ($market && !in_array($market->getId(), $marketIds)) {
                $marketIds[] = $market->getId();
            }
        }

        // Récupérer tous les managers PFT pour ces marchés en une seule requête
        $managersByMarket = [];
        if (!empty($marketIds)) {
            $markets = array_map(
                fn($id) => $this->em->getReference(Market::class, $id),
                $marketIds
            );
            $allManagers = $this->userRepo->findByRoleAndMarkets(
                \App\Enum\UserRoleEnum::PFT,
                $markets
            );

            foreach ($allManagers as $manager) {
                foreach ($manager->getMarkets() as $managedMarket) {
                    $mid = $managedMarket->getId();
                    $managersByMarket[$mid][] = $manager;
                }
                if ($manager->getMarket()) {
                    $mid = $manager->getMarket()->getId();
                    if (!isset($managersByMarket[$mid])) {
                        $managersByMarket[$mid] = [];
                    }
                    if (!in_array($manager, $managersByMarket[$mid], true)) {
                        $managersByMarket[$mid][] = $manager;
                    }
                }
            }
        }

        // Pré-charger toutes les notifications récentes pour éviter les requêtes N+1
        $depuis = new \DateTimeImmutable('-10 minutes');
        $allUserIds = [];
        foreach ($cases as $case) {
            $market = $case->getAlert()->getMarket();
            if ($market && isset($managersByMarket[$market->getId()])) {
                foreach ($managersByMarket[$market->getId()] as $manager) {
                    $allUserIds[$manager->getId()] = true;
                }
            }
        }
        $allUserIds = array_keys($allUserIds);

        $recentNotifications = [];
        if (!empty($allUserIds)) {
            $notifications = $this->notifRepo->findRecentByUsersAndTypes(
                $allUserIds,
                ['sla_proche', 'urgence'],
                $depuis
            );
            foreach ($notifications as $notif) {
                $key = $notif->getDestinataire()->getId()
                    . '|' . $notif->getType()
                    . '|' . ($notif->getAlert()?->getId() ?? 'null');
                $recentNotifications[$key] = $notif;
            }
        }

        $notified1h = 0;
        $notifiedDepass = 0;

        foreach ($cases as $case) {
            $alert = $case->getAlert();
            $heuresEcoulees = $case->getHeuresEcoulees();
            $heuresRestantes = 72 - $heuresEcoulees;
            $market = $alert->getMarket();
            $managers = $market && isset($managersByMarket[$market->getId()])
                ? $managersByMarket[$market->getId()]
                : [];

            // ── Alerte 1h avant échéance (entre 71h et 72h) ────────────────
            if ($heuresRestantes > 0 && $heuresRestantes <= 1) {
                foreach ($managers as $manager) {
                    $key = $manager->getId() . '|sla_proche|' . $alert->getId();
                    if (!isset($recentNotifications[$key])) {
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
                    $key = $manager->getId() . '|urgence|' . $alert->getId();
                    if (!isset($recentNotifications[$key])) {
                        $notif = new Notification();
                        $notif->setDestinataire($manager);
                        $notif->setAlert($alert);
                        $notif->setType('urgence');
                        $notif->setContenu(sprintf(
                            ' SLA DÉPASSÉ — Alerte %s : procédure urgence 72h dépassée de %.1f heure(s). Action immédiate requise.',
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
