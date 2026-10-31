<?php

namespace App\Controller\Urgence;

use App\Entity\Alert;
use App\Entity\AccessLog;
use App\Entity\Urgence72hCase;
use App\Entity\Urgence72hPhase;
use App\Enum\PhaseUrgence;
use App\Repository\AlertRepository;
use App\Service\CryptoService;
use App\Service\PdfExportService;
use App\Voter\AlertVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/urgence')]
#[IsGranted('ROLE_PFT')]
class UrgenceController extends AbstractController
{
    public function __construct(
        private readonly CryptoService $crypto,
    ) {
    }

    private function resolveAlertToken(string $token, AlertRepository $repo): Alert
    {
        $id = $this->crypto->decryptId($token, 'alert');
        if ($id === null) {
            throw $this->createNotFoundException('Alerte introuvable ou jeton invalide.');
        }

        $alert = $repo->find($id);
        if (!$alert) {
            throw $this->createNotFoundException('Alerte introuvable.');
        }

        return $alert;
    }

    #[Route('/activate/{token}', name: 'app_urgence_activate', methods: ['POST'], requirements: ['token' => '[A-Za-z0-9_-]+'])]
    public function activate(Request $request, string $token, AlertRepository $alertRepository, EntityManagerInterface $em): Response
    {
        $alert = $this->resolveAlertToken($token, $alertRepository);
        $this->denyAccessUnlessGranted(AlertVoter::DECIDE, $alert);

        if (!$this->isCsrfTokenValid('activate-urgence-' . $alert->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Jeton de sécurité invalide.');
            return $this->redirectToRoute('app_alert_show', ['token' => $token]);
        }

        if ($alert->getStatut() !== \App\Enum\AlertStatut::VALIDEE) {
            throw $this->createAccessDeniedException('Seule une alerte validée par le Manager peut entrer dans l’espace Urgence 72h.');
        }
        if ($alert->getUrgence() !== \App\Enum\AlertUrgence::SOIXANTE_DOUZE_H) {
            $alert->setUrgence(\App\Enum\AlertUrgence::SOIXANTE_DOUZE_H);
        }

        if ($alert->getUrgence72hCase()) {
            $this->addFlash('warning', 'Procédure Urgence 72h déjà active pour cette alerte.');
            return $this->redirectToRoute('app_alert_show', ['token' => $token]);
        }

        $justification = $request->request->get('justification');
        if (empty($justification)) {
            $this->addFlash('danger', 'Une justification est obligatoire pour l\'activation manuelle.');
            return $this->redirectToRoute('app_alert_show', ['token' => $token]);
        }

        $case = new Urgence72hCase();
        $case->setAlert($alert);
        $case->setActivationManuelle(true);
        $case->setJustificationManuelle($justification);
        $case->setDateActivation(new \DateTimeImmutable());

        $user = $this->getUser();
        if ($user instanceof \App\Entity\User) {
            $case->setPftResponsable($user);
        }

        foreach (PhaseUrgence::cases() as $phaseEnum) {
            $phase = new Urgence72hPhase();
            $phase->setPhase($phaseEnum);
            $slaLimite = $case->getDateActivation()->modify('+' . $phaseEnum->slaHeures() . ' hours');
            $phase->setSlaHeureLimite($slaLimite);

            if ($phaseEnum === PhaseUrgence::DETECTION) {
                // Détection (T0) automatiquement complétée dès sa création
                $phase->setDateDebut($case->getDateActivation());
                $phase->setDateFin($case->getDateActivation());
            } elseif ($phaseEnum === PhaseUrgence::COORDINATION) {
                $phase->setDateDebut($case->getDateActivation());
            }

            $case->addPhase($phase);
        }

        $alert->setUrgence72hCase($case);
        $em->persist($case);
        $em->flush();

        $this->addFlash('success', 'Procédure Urgence 72h activée manuellement.');

        return $this->redirectToRoute('app_dashboard_urgence');
    }

    #[Route('/phase/{id}/advance', name: 'app_urgence_phase_advance', methods: ['POST'])]
    public function advancePhase(Request $request, Urgence72hPhase $phase, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('advance-phase-' . $phase->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Jeton de sécurité invalide.');
            return $this->redirectToRoute('app_dashboard_urgence');
        }

        $case = $phase->getUrgenceCase();
        $alert = $case?->getAlert();

        if (!$case || !$case->isActive() || !$alert || !$alert->isEligibleForUrgence72h() || $phase->isTerminee()) {
            throw $this->createAccessDeniedException('Ce cas n’est plus éligible au processus opérationnel Urgence 72h.');
        }

        $this->denyAccessUnlessGranted(AlertVoter::DECIDE, $alert);

        $now = new \DateTimeImmutable();
        $phase->setDateFin($now);
        if ($now > $phase->getSlaHeureLimite()) {
            $phase->setEnRetard(true);
        }

        // Si phase COORDINATION terminée -> démarrer la phase SUIVI
        if ($phase->getPhase() === PhaseUrgence::COORDINATION) {
            foreach ($case->getPhases() as $otherPhase) {
                if ($otherPhase->getPhase() === PhaseUrgence::SUIVI && $otherPhase->getDateDebut() === null) {
                    $otherPhase->setDateDebut($now);
                }
            }
        }

        // Si dernière phase (SUIVI), clôturer le cas
        if ($phase->getPhase() === PhaseUrgence::SUIVI) {
            $case->setStatutCase('cloturee');
            $case->setDateCloture(new \DateTime());
            $case->setDelaiReelHeures($case->getHeuresEcoulees());
        }

        $em->flush();

        $this->addFlash('success', sprintf('Phase "%s" validée et marquée comme complétée.', $phase->getPhase()->label()));

        return $this->redirectToRoute('app_dashboard_urgence');
    }

    #[Route('/phase/{id}/observation', name: 'app_urgence_phase_observation', methods: ['POST'])]
    public function savePhaseObservation(Request $request, Urgence72hPhase $phase, EntityManagerInterface $em): Response
    {
        $case = $phase->getUrgenceCase();
        $alert = $case?->getAlert();

        if (
            !$case
            || !$case->isActive()
            || !$alert
            || !$alert->isEligibleForUrgence72h()
            || !in_array($phase->getPhase(), [PhaseUrgence::COORDINATION, PhaseUrgence::SUIVI], true)
        ) {
            throw $this->createAccessDeniedException('Ce cas n’est plus éligible au processus opérationnel Urgence 72h.');
        }

        $this->denyAccessUnlessGranted(AlertVoter::DECIDE, $alert);

        if (!$this->isCsrfTokenValid('phase-observation-' . $phase->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Jeton de sécurité invalide.');
            return $this->redirectToRoute('app_dashboard_urgence');
        }

        $commentaire = $request->request->get('commentaire');
        if (!is_string($commentaire) || strlen($commentaire) > 20000) {
            $this->addFlash('danger', 'L’observation est invalide ou dépasse la taille maximale autorisée.');
            return $this->redirectToRoute('app_dashboard_urgence');
        }

        $commentaire = trim($commentaire);
        $ancienneObservation = $phase->getCommentaire();
        $nouvelleObservation = $commentaire === '' ? null : $commentaire;

        if ($ancienneObservation !== $nouvelleObservation) {
            $phase->setCommentaire($nouvelleObservation);

            $user = $this->getUser();
            if ($user instanceof \App\Entity\User) {
                $log = new AccessLog();
                $log->setAction(AccessLog::ACTION_MODIFICATION);
                $log->setUser($user);
                $log->setAlert($alert);
                $log->setDetails(sprintf('Observation de la phase « %s » du dossier Urgence 72h #%d', $phase->getPhase()->label(), $case->getId()));
                $log->setDiffData([
                    'before' => ['observation' => $ancienneObservation],
                    'after' => ['observation' => $nouvelleObservation],
                ]);
                $log->setIpAdresse($request->getClientIp());
                $userAgent = $request->headers->get('User-Agent');
                $log->setUserAgent($userAgent !== null ? substr($userAgent, 0, 255) : null);
                $em->persist($log);
            }

            $em->flush();
            $this->addFlash('success', 'Observation enregistrée.');
        } else {
            $this->addFlash('info', 'Aucune modification à enregistrer.');
        }

        return $this->redirectToRoute('app_dashboard_urgence');
    }

    #[Route('/dossier/{id}/pdf', name: 'app_urgence_case_pdf', methods: ['GET'])]
    public function exportCasePdf(
        Urgence72hCase $case,
        PdfExportService $pdfService,
        EntityManagerInterface $em,
        Request $request,
    ): Response {
        $alert = $case->getAlert();
        if (!$alert) {
            throw $this->createNotFoundException('Alerte associée au dossier introuvable.');
        }

        $this->denyAccessUnlessGranted(AlertVoter::EXPORT, $alert);
        $pdfContent = $pdfService->generateUrgence72hPdf($case);

        $user = $this->getUser();
        if ($user instanceof \App\Entity\User) {
            $log = new AccessLog();
            $log->setAction(AccessLog::ACTION_EXPORT);
            $log->setUser($user);
            $log->setAlert($alert);
            $log->setDetails(sprintf('Export PDF du dossier Urgence 72h #%d', $case->getId()));
            $log->setIpAdresse($request->getClientIp());
            $userAgent = $request->headers->get('User-Agent');
            $log->setUserAgent($userAgent !== null ? substr($userAgent, 0, 255) : null);
            $em->persist($log);
            $em->flush();
        }

        $code = preg_replace('/[^A-Za-z0-9_-]/', '_', $alert->getCodeGei() ?? 'Dossier') ?: 'Dossier';

        return new Response($pdfContent, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('attachment; filename="Suivi_72h_%s.pdf"', $code),
        ]);
    }
}
