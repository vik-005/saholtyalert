<?php

namespace App\Command;

use App\Entity\IpAddressReputation;
use App\Repository\IpAddressReputationRepository;
use App\Repository\SecurityAuditLogRepository;
use App\Service\ThreatIntelligenceService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Commande de rafraîchissement des réputations IP.
 *
 * Cron recommandé (toutes les 6h) :
 *   0 *\/6 * * * php /chemin/bin/console app:security:refresh-ip-reputations >> /var/log/gei-security.log 2>&1
 *
 * Actions effectuées :
 *   1. Rafraîchit les enregistrements expirés (TTL 24h)
 *   2. Crée des enregistrements pour les IP actives sans réputation
 *   3. Met à jour les scores de risque synthétiques
 */
#[AsCommand(
    name: 'app:security:refresh-ip-reputations',
    description: 'Rafraîchit les réputations IP via les services Threat Intelligence',
    aliases: ['app:security:refresh-ips'],
)]
class SecurityRefreshIpReputationsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface        $em,
        private readonly IpAddressReputationRepository $reputationRepo,
        private readonly SecurityAuditLogRepository    $auditLogRepo,
        private readonly ThreatIntelligenceService     $threatIntel,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'limit',
                null,
                InputOption::VALUE_OPTIONAL,
                'Nombre maximum d\'IP à traiter par exécution',
                50
            )
            ->addOption(
                'force',
                null,
                InputOption::VALUE_NONE,
                'Forcer le rafraîchissement même si les enregistrements ne sont pas expirés'
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

        $io     = new SymfonyStyle($input, $output);
        $limit  = (int) $input->getOption('limit');
        $force  = $input->getOption('force');
        $dryRun = $input->getOption('dry-run');

        $io->title('GEI Security — Rafraîchissement des réputations IP');

        if ($dryRun) {
            $io->warning('[DRY-RUN] Aucune modification ne sera effectuée.');
        }

        $refreshed = 0;
        $created   = 0;
        $skipped   = 0;

        // ── 1. Rafraîchir les enregistrements expirés ─────────────────────
        $io->section('1. Enregistrements expirés à rafraîchir');

        $expiredRecords = $this->reputationRepo->findExpiredRecords($limit);

        if (empty($expiredRecords)) {
            $io->text(' Aucun enregistrement expiré trouvé.');
        } else {
            $io->text(sprintf(' %d enregistrement(s) expiré(s) trouvé(s).', count($expiredRecords)));

            $io->progressStart(count($expiredRecords));
            foreach ($expiredRecords as $record) {
                if (!$dryRun) {
                    try {
                        $this->threatIntel->enrichIpData($record->getIpAddress());
                        $refreshed++;
                    } catch (\Exception $e) {
                        $io->warning(sprintf('  Échec rafraîchissement %s : %s', $record->getIpAddress(), $e->getMessage()));
                        $skipped++;
                    }
                }
                $io->progressAdvance();
            }
            $io->progressFinish();
        }

        // ── 2. Créer des enregistrements pour les IP actives sans réputation
        $io->section('2. Nouvelles IP actives sans réputation');

        // Trouver les IP récentes dans les journaux d'audit sans enregistrement de réputation
        $since  = new \DateTimeImmutable('-24 hours');
        $newIps = $this->findNewActiveIps($since, $limit - $refreshed);

        if (empty($newIps)) {
            $io->text(' Aucune nouvelle IP active à enregistrer.');
        } else {
            $io->text(sprintf(' %d nouvelle(s) IP active(s) trouvée(s).', count($newIps)));

            $io->progressStart(count($newIps));
            foreach ($newIps as $ip) {
                if (!$dryRun) {
                    try {
                        $existing = $this->reputationRepo->findByIpAddress($ip);
                        if ($existing === null) {
                            $this->threatIntel->enrichIpData($ip);
                            $created++;
                        }
                    } catch (\Exception $e) {
                        $skipped++;
                    }
                }
                $io->progressAdvance();
            }
            $io->progressFinish();
        }

        // ── 3. Mise à jour des scores de risque ───────────────────────────
        $io->section('3. Mise à jour des scores de risque');

        if (!$dryRun) {
            $updated = $this->updateRiskScores();
            $io->text(sprintf(' %d score(s) de risque mis à jour.', $updated));
        } else {
            $io->text(' [DRY-RUN] Mise à jour des scores ignorée.');
        }

        // ── 4. Résumé ──────────────────────────────────────────────────────
        $io->section('Résumé');
        $io->definitionList(
            ['Enregistrements rafraîchis' => $refreshed],
            ['Nouveaux enregistrements'   => $created],
            ['Ignorés (erreurs)'          => $skipped],
            ['Threat Intel activé'        => $this->threatIntel->isThreatIntelEnabled() ? 'Oui' : 'Non (simulé)'],
            ['Mode simulation'            => $dryRun ? 'Oui' : 'Non'],
        );

        $io->success('Rafraîchissement des réputations IP terminé.');

        return Command::SUCCESS;
    }

    /**
     * Trouve les IP actives des dernières 24h sans enregistrement de réputation.
     */
    private function findNewActiveIps(\DateTimeImmutable $since, int $limit): array
    {
        $conn = $this->em->getConnection();

        $rows = $conn->fetchAllAssociative(
            'SELECT DISTINCT sal.ip_address
             FROM security_audit_log sal
             LEFT JOIN ip_address_reputation iar ON iar.ip_address = sal.ip_address
             WHERE sal.created_at >= :since
               AND iar.id IS NULL
             LIMIT :limit',
            ['since' => $since->format('Y-m-d H:i:s'), 'limit' => max(1, $limit)]
        );

        return array_column($rows, 'ip_address');
    }

    /**
     * Met à jour les scores de risque synthétiques basés sur les données disponibles.
     */
    private function updateRiskScores(): int
    {
        // Recalcule le score de risque pour toutes les IPs avec données AbuseIPDB
        $result = $this->em->getConnection()->executeStatement(
            "UPDATE ip_address_reputation
             SET risk_score = LEAST(100, (
                 COALESCE(abuse_ip_db_score * 0.5, 0)
               + CASE WHEN is_tor   = 1 THEN 20 ELSE 0 END
               + CASE WHEN is_vpn   = 1 THEN 10 ELSE 0 END
               + CASE WHEN is_proxy = 1 THEN 5  ELSE 0 END
               + CASE WHEN network_type IN ('datacenter', 'hosting') THEN 5 ELSE 0 END
             )),
             updated_at = NOW()
             WHERE last_checked_at IS NOT NULL"
        );

        return (int) $result;
    }
}
