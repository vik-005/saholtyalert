<?php

namespace App\Controller\Urgence;

use App\Entity\Alert;
use App\Entity\Urgence72hCase;
use App\Entity\Urgence72hPhase;
use App\Enum\PhaseUrgence;
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
    #[Route('/activate/{id}', name: 'app_urgence_activate', methods: ['POST'])]
    public function activate(Request $request, Alert $alert, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('activate-urgence-' . $alert->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Jeton de sécurité invalide.');
            return $this->redirectToRoute('app_alert_show', ['id' => $alert->getId()]);
        }

        if (!$alert->isEligibleForUrgence72h()) {
            throw $this->createAccessDeniedException('Seule une alerte validée par le Manager et classée Urgence 72h peut entrer dans cet espace.');
        }

        if ($alert->getUrgence72hCase()) {
            $this->addFlash('warning', 'Procédure Urgence 72h déjà active pour cette alerte.');
            return $this->redirectToRoute('app_alert_show', ['id' => $alert->getId()]);
        }

        $justification = $request->request->get('justification');
        if (empty($justification)) {
            $this->addFlash('danger', 'Une justification est obligatoire pour l\'activation manuelle.');
            return $this->redirectToRoute('app_alert_show', ['id' => $alert->getId()]);
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

        $now = new \DateTimeImmutable();
        $phase->setDateFin($now);
        if ($now > $phase->getSlaHeureLimite()) {
            $phase->setEnRetard(true);
        }

        $case = $phase->getUrgenceCase();

        if (!$case || !$case->getAlert()?->isEligibleForUrgence72h()) {
            throw $this->createAccessDeniedException('Ce cas n’est plus éligible au processus opérationnel Urgence 72h.');
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
}
