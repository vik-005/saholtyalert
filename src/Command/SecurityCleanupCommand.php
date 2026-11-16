<?php

namespace App\Command;

use App\Entity\IpBlocklist;
use App\Entity\SecurityAuditLog;
use App\Repository\IpBlocklistRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Commande de nettoyage du module de sécurité.
 *
 * Cron recommandé (quotidien à 03h00) :
 *   0 3 * * * php /chemin/bin/console app:security:cleanup >> /var/log/gei-security.log 2>&1
 *
 * Actions effectuées :
 *   1. Désactive les blocages IP expirés
 *   2. Archive les journaux d'audit trop anciens (configurable)
 *   3. Résout automatiquement les alertes de sécurité clôturées
 *   4. Affiche un rapport de synthèse
 */
#[AsCommand(
    name: 'app:security:cleanup',
    description: 'Nettoie les blocages IP expirés et archive les journaux anciens',
)]
class SecurityCleanupCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly IpBlocklistRepository  $blocklistRepo,
        private readonly Connection             $conn,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'retention-days',
                null,
                InputOption::VALUE_OPTIONAL,
                'Nombre de jours de rétention des journaux d\'audit (0 = pas d\'archivage)',
                365
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Simulation sans modifications réelles'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        set_time_limit(300);

        $io        = new SymfonyStyle($input, $output);
        $dryRun    = $input->getOption('dry-run');
        $retention = (int) $input->getOption('retention-days');

        $io->title('GEI Security — Nettoyage du module de sécurité');

        if ($dryRun) {
            $io->warning('[DRY-RUN] Aucune modification ne sera effectuée.');
        }

        $totalCleaned = 0;

        // ── 1. Désactiver les blocages IP expirés ─────────────────────────
        $io->section('1. Blocages IP expirés');

        $expiredBlocks = $this->blocklistRepo->findExpiredEntries();
        $expiredCount  = count($expiredBlocks);

        if ($expiredCount === 0) {
            $io->text(' Aucun blocage expiré à désactiver.');
        } else {
            $io->text(sprintf(' %d blocage(s) expiré(s) trouvé(s).', $expiredCount));

            if (!$dryRun) {
                foreach ($expiredBlocks as $block) {
                    $block->setIsActive(false);
                }
                $this->em->flush();
            }

            $io->success(sprintf('%d blocage(s) désactivé(s).', $expiredCount));
            $totalCleaned += $expiredCount;
        }

        // ── 2. Archiver les journaux d'audit trop anciens ─────────────────
        $io->section('2. Rétention des journaux d\'audit');

        if ($retention <= 0) {
            $io->text(' Archivage désactivé (retention-days=0).');
        } else {
            $threshold = new \DateTimeImmutable("-{$retention} days");

            // Compter les lignes à supprimer
            $countOld = (int) $this->conn->fetchOne(
                'SELECT COUNT(*) FROM security_audit_log WHERE created_at < ?',
                [$threshold->format('Y-m-d H:i:s')]
            );

            if ($countOld === 0) {
                $io->text(sprintf(' Aucun journal de plus de %d jours à supprimer.', $retention));
            } else {
                $io->text(sprintf(' %d ligne(s) de journal plus ancienne(s) que %d jours.', $countOld, $retention));

                if (!$dryRun) {
                    // Suppression par lots de 10 000 pour ne pas verrouiller la table
                    $deleted = 0;
                    do {
                        $batch = $this->conn->executeStatement(
                            'DELETE FROM security_audit_log WHERE created_at < ? LIMIT 10000',
                            [$threshold->format('Y-m-d H:i:s')]
                        );
                        $deleted += $batch;
                    } while ($batch === 10000);

                    $io->success(sprintf('%d ligne(s) de journal supprimée(s).', $deleted));
                    $totalCleaned += $deleted;
                } else {
                    $io->text(sprintf(' [DRY-RUN] %d ligne(s) auraient été supprimées.', $countOld));
                }
            }
        }

        // ── 3. Résoudre les alertes de sécurité anciennes non traitées ───
        $io->section('3. Alertes de sécurité non traitées (> 30 jours)');

        $thirtyDaysAgo = new \DateTimeImmutable('-30 days');
        $staleCount    = (int) $this->conn->fetchOne(
            "SELECT COUNT(*) FROM security_alert WHERE status = 'new' AND created_at < ?",
            [$thirtyDaysAgo->format('Y-m-d H:i:s')]
        );

        if ($staleCount === 0) {
            $io->text(' Aucune alerte périmée trouvée.');
        } else {
            if (!$dryRun) {
                $this->conn->executeStatement(
                    "UPDATE security_alert SET status = 'resolved', resolved_at = NOW(), updated_at = NOW()
                     WHERE status = 'new' AND created_at < ?",
                    [$thirtyDaysAgo->format('Y-m-d H:i:s')]
                );
            }
            $io->success(sprintf('%d alerte(s) automatiquement résolue(s).', $staleCount));
            $totalCleaned += $staleCount;
        }

        // ── 4. Résumé ──────────────────────────────────────────────────────
        $io->section('Résumé');
        $io->definitionList(
            ['Blocages IP désactivés'    => $expiredCount],
            ['Journaux supprimés'        => $retention > 0 && !$dryRun ? ($totalCleaned - $expiredCount - $staleCount) : 'N/A'],
            ['Alertes auto-résolues'     => $staleCount],
            ['Mode simulation'           => $dryRun ? 'Oui' : 'Non'],
        );

        $io->success('Nettoyage de sécurité terminé.');

        return Command::SUCCESS;
    }
}
