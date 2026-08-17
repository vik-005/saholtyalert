<?php

namespace App\Controller\Alert;

use App\Entity\Alert;
use App\Form\QualificationType;
use App\Service\ScoreCalculatorService;
use App\Voter\AlertVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/alert')]
#[IsGranted('ROLE_USER')]
class QualificationController extends AbstractController
{
    #[Route('/{id}/qualify', name: 'app_alert_qualify', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function qualify(
        Request $request,
        Alert $alert,
        ScoreCalculatorService $scoreCalculator,
        EntityManagerInterface $em,
    ): Response {
        $this->denyAccessUnlessGranted(AlertVoter::QUALIFY, $alert);

        $form = $this->createForm(QualificationType::class, $alert);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $scoreCalculator->calculate($alert);

            $user = $this->getUser();
            $userLabel = $user instanceof \App\Entity\User ? $user->getNomComplet() : 'system';
            $history = $scoreCalculator->createHistoryEntry($alert, $userLabel);
            $em->persist($history);

            $em->flush();

            $this->addFlash('success', sprintf(
                'Alerte qualifiée avec succès ! Score GEI : %d (%s)',
                $alert->getScoreGei(),
                $alert->getNiveauPriorite()?->label()
            ));

            return $this->redirectToRoute('app_alert_show', ['id' => $alert->getId()]);
        }

        return $this->render('alert/qualification.html.twig', [
            'alert' => $alert,
            'form' => $form,
        ]);
    }

    #[Route('/{id}/override-score', name: 'app_alert_override_score', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function overrideScore(Request $request, Alert $alert, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted(AlertVoter::QUALIFY, $alert);

        $score = $request->request->get('score_surcharge');
        $prioriteVal = $request->request->get('priorite_surcharge');
        $justification = $request->request->get('justification_surcharge');

        if (empty($justification)) {
            $this->addFlash('danger', 'Une justification est obligatoire pour surcharger manuellement le score/priorité.');
            return $this->redirectToRoute('app_alert_qualify', ['id' => $alert->getId()]);
        }

        $user = $this->getUser();
        if ($user instanceof \App\Entity\User) {
            $alert->setSurchargePar($user);
        }

        if ($score !== null && $score !== '') {
            $alert->setScoreSurcharge((int)$score);
        } else {
            $alert->setScoreSurcharge(null);
        }

        if (!empty($prioriteVal)) {
            $alert->setNiveauPrioriteSurcharge(\App\Enum\NiveauPriorite::from($prioriteVal));
        } else {
            $alert->setNiveauPrioriteSurcharge(null);
        }

        $alert->setJustificationSurcharge($justification);
        $em->flush();

        $this->addFlash('success', 'Surcharge manuelle enregistrée par le manager.');

        return $this->redirectToRoute('app_alert_show', ['id' => $alert->getId()]);
    }

    #[Route('/api/preview-score', name: 'app_alert_preview_score', methods: ['POST'])]
    public function previewScore(Request $request, ScoreCalculatorService $scoreCalculator): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];

        try {
            $result = $scoreCalculator->computeFromValues(
                fiabilite: $data['fiabilite'] ?? 'C',
                credibilite: (int)($data['credibilite'] ?? 3),
                urgence: $data['urgence'] ?? 'routine',
                impact: $data['impact'] ?? 'moyen',
                exploitabilite: $data['exploitabilite'] ?? 'a_completer',
            );

            return new JsonResponse($result);
        } catch (\Exception $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }
    }
}

