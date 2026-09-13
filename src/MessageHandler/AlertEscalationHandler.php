<?php

namespace App\MessageHandler;

use App\Entity\Alert;
use App\Entity\Notification;
use App\Enum\UserRoleEnum;
use App\Message\AlertEscalationMessage;
use App\Repository\AlertRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Twig\Environment;

/**
 * Handler des messages d'escalade GEI.
 *
 * Traite les 7 types de déclenchement définis par EscalationRuleEngine
 * et crée les notifications en base + entrées de log pour chaque règle.
 *
 * Partie D — Cycle Soumission → Notification → Validation :
 *   - urgence_72h_created     : Règle 1 — Score ≥ 18
 *   - transmission_prioritaire : Règle 2 — Urgence Immédiat + Impact Élevé
 *   - tache_analyse_rapide     : Règle 3 — Exploitabilité Actionnable + Crédibilité ≥ 2
 *   - multi_corridor_comite_ait: Règle 4 — Multi-pays/corridor
 *   - validation_saholty_requise: Règle 5 — Score 14–17
 *   - demande_corroboration    : Règle 6 — Fiabilité C/D + Impact Élevé
 *   - surveillance_renforcee   : Règle 7 — Crédibilité 3 + Urgence élevée
 */
#[AsMessageHandler]
class AlertEscalationHandler
{
    public function __construct(
        private readonly LoggerInterface       $logger,
        private readonly EntityManagerInterface $em,
        private readonly AlertRepository       $alertRepository,
        private readonly UserRepository        $userRepository,
        private readonly MailerInterface       $mailer,
        private readonly Environment           $twig,
    ) {}

    public function __invoke(AlertEscalationMessage $message): void
    {
        $alert = $this->alertRepository->find($message->getAlertId());

        if (null === $alert) {
            $this->logger->warning(sprintf(
                '[EscalationHandler] Alerte #%d introuvable pour le type %s',
                $message->getAlertId(),
                $message->getType()
            ));
            return;
        }

        $this->logger->info(sprintf(
            '[GEI Escalade] Alerte %s — Règle : %s — Détails : %s',
            $alert->getCodeGei() ?? '#' . $alert->getId(),
            $message->getType(),
            json_encode($message->getDetails())
        ));

        match($message->getType()) {
            'urgence_72h_created'       => $this->handleUrgence72h($alert, $message->getDetails()),
            'transmission_prioritaire'  => $this->handleTransmissionPrioritaire($alert),
            'tache_analyse_rapide'      => $this->handleTacheAnalyseRapide($alert, $message->getDetails()),
            'multi_corridor_comite_ait' => $this->handleMultiCorridorComiteAit($alert),
            'validation_saholty_requise' => $this->handleValidationSaholty($alert, $message->getDetails()),
            'demande_corroboration'     => $this->handleDemandeCorroboration($alert),
            'surveillance_renforcee'    => $this->handleSurveillanceRenforcee($alert),
            default => $this->logger->warning(sprintf('[EscalationHandler] Type inconnu : %s', $message->getType())),
        };

        $this->em->flush();
    }

    /**
      * Règle 1 : Score ≥ 18 → notification urgence critique à TOUS les PFT + SAHOLTY du marché.
      */
    private function handleUrgence72h(Alert $alert, array $details): void
    {
        $managers = $this->getManagersForAlert($alert);
        $contenu  = sprintf(
            ' CRITIQUE — Alerte %s — Score %d — Procédure urgence 72h activée automatiquement.',
            $alert->getCodeGei() ?? '#' . $alert->getId(),
            $details['score'] ?? $alert->getScoreGei()
        );

        foreach ($managers as $manager) {
            $this->createNotification($manager, $contenu, 'urgence', $alert);
            $this->sendCriticalEmail($manager, $alert, $details['score'] ?? $alert->getScoreGei());
        }
    }

    /**
     * Règle 2 : Urgence Immédiat + Impact Élevé → transmission prioritaire.
     */
    private function handleTransmissionPrioritaire(Alert $alert): void
    {
        $managers = $this->getManagersForAlert($alert);
        $contenu  = sprintf(
            'TRANSMISSION PRIORITAIRE — Alerte %s — Urgence Immédiat + Impact Élevé détectés.',
            $alert->getCodeGei() ?? '#' . $alert->getId()
        );

        foreach ($managers as $manager) {
            $this->createNotification($manager, $contenu, 'warning', $alert);
        }
    }

    /**
     * Règle 3 : Exploitabilité Actionnable + Crédibilité ≥ 2 → tâche analyse rapide pour Manager.
     */
    private function handleTacheAnalyseRapide(Alert $alert, array $details): void
    {
        $managers  = $this->getManagersForAlert($alert);
        $echeance  = $details['echeance_heures'] ?? 6;
        $contenu   = sprintf(
            '📋 ANALYSE RAPIDE requise — Alerte %s — Actionnable + Crédible. Échéance : %dh.',
            $alert->getCodeGei() ?? '#' . $alert->getId(),
            $echeance
        );

        foreach ($managers as $manager) {
            $this->createNotification($manager, $contenu, 'tache', $alert);
        }
    }

    /**
     * Règle 4 : Multi-corridor → escalade au Comité AIT.
     */
    private function handleMultiCorridorComiteAit(Alert $alert): void
    {
        $comiteUsers = $this->userRepository->findByRole(UserRoleEnum::COMITE_AIT);
        $saholtyUsers = $this->userRepository->findByRole(UserRoleEnum::SAHOLTY);
        $destinataires = array_merge($comiteUsers, $saholtyUsers);

        $contenu = sprintf(
            '🌍 ESCALADE COMITÉ AIT — Alerte %s — Signal multi-corridor/multi-pays détecté.',
            $alert->getCodeGei() ?? '#' . $alert->getId()
        );

        foreach ($destinataires as $dest) {
            $this->createNotification($dest, $contenu, 'escalade', $alert);
        }
    }

    /**
     * Règle 5 : Score 14–17 → blocage en attente de validation SAHOLTY.
     */
    private function handleValidationSaholty(Alert $alert, array $details): void
    {
        $saholtyUsers = $this->userRepository->findByRole(UserRoleEnum::SAHOLTY);
        $contenu      = sprintf(
            'VALIDATION REQUISE — Alerte %s — Score %d (Élevé) — Transmission bloquée jusqu\'à validation GEI explicite.',
            $alert->getCodeGei() ?? '#' . $alert->getId(),
            $details['score'] ?? $alert->getScoreGei()
        );

        foreach ($saholtyUsers as $saholty) {
            $this->createNotification($saholty, $contenu, 'validation', $alert);
        }

        // Notifier aussi le Manager responsable
        $managers = $this->getManagersForAlert($alert);
        foreach ($managers as $manager) {
            $this->createNotification($manager, $contenu, 'validation', $alert);
        }
    }

    /**
     * Règle 6 : Fiabilité C/D + Impact Élevé → demande de corroboration.
     */
    private function handleDemandeCorroboration(Alert $alert): void
    {
        $managers = $this->getManagersForAlert($alert);
        $contenu  = sprintf(
            '🔍 CORROBORATION REQUISE — Alerte %s — Fiabilité source faible (C/D) avec Impact Élevé.',
            $alert->getCodeGei() ?? '#' . $alert->getId()
        );

        foreach ($managers as $manager) {
            $this->createNotification($manager, $contenu, 'tache', $alert);
        }
    }

    /**
     * Règle 7 : Crédibilité 3 + Urgence élevée → flag surveillance renforcée.
     */
    private function handleSurveillanceRenforcee(Alert $alert): void
    {
        $managers = $this->getManagersForAlert($alert);
        $contenu  = sprintf(
            '
            SURVEILLANCE RENFORCÉE — Alerte %s — Crédibilité douteuse avec urgence élevée. Reste en tête de liste.',
            $alert->getCodeGei() ?? '#' . $alert->getId()
        );

        foreach ($managers as $manager) {
            $this->createNotification($manager, $contenu, 'info', $alert);
        }
    }

    // ─── Helpers ────────────────────────────────────────────────────────────────

    private function getManagersForAlert(Alert $alert): array
    {
        $market = $alert->getMarket();
        if (null === $market) {
            return $this->userRepository->findByRole(UserRoleEnum::PFT);
        }

        return $this->userRepository->findByRoleAndMarket(UserRoleEnum::PFT, $market);
    }

    private function createNotification(\App\Entity\User $destinataire, string $contenu, string $type, Alert $alert): void
    {
        $notif = new Notification();
        $notif->setDestinataire($destinataire);
        $notif->setContenu($contenu);
        $notif->setType($type);
        $notif->setAlert($alert);

        $this->em->persist($notif);
    }

    private function sendCriticalEmail(\App\Entity\User $destinataire, Alert $alert, int $score): void
    {
        if (!$destinataire->getEmail()) {
            return;
        }

        $html = $this->twig->render('emails/alerte_critique.html.twig', [
            'codeGei'  => $alert->getCodeGei() ?? '#' . $alert->getId(),
            'score'    => $score,
            'market'   => $alert->getMarket()?->getNom() ?? '—',
            'priorite' => \App\Enum\NiveauPriorite::fromScore($score)->label(),
            'url'      => sprintf('%s/alert/%d', rtrim($_SERVER['APP_URL'] ?? 'http://localhost'), $alert->getId()),
        ]);

        $email = (new Email())
            ->from('gei@example.com')
            ->to($destinataire->getEmail())
            ->subject(sprintf('[URGENT] Alerte Critique GEI — %s (Score %d)', $alert->getCodeGei() ?? '#' . $alert->getId(), $score))
            ->html($html);

        $this->mailer->send($email);
    }
}
