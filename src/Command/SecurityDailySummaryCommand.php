<?php

namespace App\Command;

use App\Service\SecurityAlertEmailService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Commande d'envoi du résumé quotidien de sécurité.
 *
 * Cron recommandé (tous les jours à 08h00) :
 *   0 8 * * * php /chemin/bin/console app:security:daily-summary >> /var/log/gei-security.log 2>&1
 */
#[AsCommand(
    name: 'app:security:daily-summary',
    description: 'Envoie le rapport de sécurité quotidien par email aux administrateurs',
)]
class SecurityDailySummaryCommand extends Command
{
    public function __construct(
        private readonly SecurityAlertEmailService $emailService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('GEI Security — Envoi du rapport quotidien');

        try {
            $this->emailService->sendDailySummary();
            $io->success('Rapport quotidien envoyé avec succès.');
        } catch (\Throwable $e) {
            $io->error('Erreur lors de l\'envoi : ' . $e->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
