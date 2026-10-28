<?php

namespace App\Controller\Alert;

use App\Entity\Alert;
use App\Entity\AlertStatusHistory;
use App\Entity\User;
use App\Enum\AlertLegality;
use App\Enum\AlertStatut;
use App\Enum\NiveauPriorite;
use App\Form\QualificationType;
use App\Repository\AlertRepository;
use App\Service\CryptoService;
use App\Service\EscalationRuleEngine;
use App\Service\NotificationService;
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
    public function __construct(
        private readonly CryptoService $crypto,
    ) {}

    private function resolveAlertToken(string $token, AlertRepository $repo): Alert
    {
        $id = $this->crypto->decryptId($token, 'alert');
        if ($id === null) {
            throw $this->createNotFoundException('Fiche d\'alerte introuvable.');
        }
        $alert = $repo->find($id);
        if (!$alert instanceof Alert) {
            throw $this->createNotFoundException('Fiche d\'alerte introuvable.');
        }
        return $alert;
    }

    /**
     * Point d'entrée unique de qualification/validation.
     *
     * Deux modes selon le statut de l'alerte et le rôle de l'utilisateur :
     *  - Agent : soumet depuis le wizard.
     *  - Manager (PFT) : vérifie, corrige les critères autorisés, valide ou rejette.
     *
     * La variable canDecide = true quand l'alerte est prête pour décision Manager
     * (statut A_VALIDER_SAHOLTY) et que l'utilisateur courant a le droit de décider.
     */
    #[Route('/{token}/qualify', name: 'app_alert_qualify', methods: ['GET', 'POST'], requirements: ['token' => '[A-Za-z0-9_-]+'])]
    public function qualify(
        string $token,
        Request $request,
        AlertRepository $alertRepository,
        ScoreCalculatorService $scoreCalculator,
        EntityManagerInterface $em,
        NotificationService $notificationService,
        EscalationRuleEngine $escalationEngine,
    ): Response {
        $alert = $this->resolveAlertToken($token, $alertRepository);
        $this->denyAccessUnlessGranted(AlertVoter::DECIDE, $alert);

        /** @var User|null $currentUser */
        $currentUser = $this->getUser();

        if ($alert->getStatut() !== AlertStatut::A_VALIDER_SAHOLTY) {
            throw $this->createAccessDeniedException(
                'Seule une alerte en attente de validation Manager peut être qualifiée.'
            );
        }

        // canDecide = true → le Manager voit l'écran de décision (lecture seule + actions Manager)
        $canDecide = $this->isGranted(AlertVoter::DECIDE, $alert);

        if (!$canDecide) {
            throw $this->createAccessDeniedException('Cette alerte ne peut pas être décidée à cette étape.');
        }

        // ── VÉRIFICATION DE LA LIMITE DE REQUALIFICATIONS ──────────────────────────
        // Manager (PFT) : limité à 3 requalifications
        // SUPERADMIN et SAHOLTY : illimité
        if ($currentUser instanceof User && !$alert->canBeRequalifiedBy($currentUser)) {
            return $this->redirectToRoute('app_alert_requalification_limit', ['token' => $token]);
        }

        $isReject = $request->request->has('action_rejet');

        // ── REJET Manager — formulaire séparé sans les champs de qualification ──────
        if ($isReject && $canDecide) {
            if (!$this->isCsrfTokenValid('qualify-' . $alert->getId(), $request->request->get('_token'))) {
                $this->addFlash('danger', 'Jeton de sécurité invalide.');
                return $this->redirectToRoute('app_alert_qualify', ['token' => $token]);
            }

            $commentaireRejet = trim((string) $request->request->get('commentaire_rejet', ''));

            if ($commentaireRejet === '') {
                $this->addFlash('danger', 'Un commentaire est obligatoire pour rejeter une fiche.');
                return $this->redirectToRoute('app_alert_qualify', ['token' => $token]);
            }

            $ancienStatut = $alert->getStatut();

            $em->wrapInTransaction(function () use ($alert, $commentaireRejet, $em, $ancienStatut, $currentUser) {
                $alert->setCommentaireRejet($commentaireRejet);
                $alert->setStatut(AlertStatut::A_COMPLETER);

                // Traçabilité nominative Manager (Partie B)
                $alert->setValidatedBy($currentUser instanceof User ? $currentUser : null);
                $alert->setDateValidation(new \DateTime());

                $history = new AlertStatusHistory();
                $history->setAlert($alert);
                $history->setAncienStatut($ancienStatut);
                $history->setNouveauStatut(AlertStatut::A_COMPLETER);
                $history->setChangedBy($currentUser instanceof User ? $currentUser : null);
                $history->setTransitionName('rejet');
                $history->setJustification(sprintf(
                    'Rejet par %s : %s',
                    $currentUser instanceof User ? $currentUser->getNomComplet() : 'Manager',
                    $commentaireRejet
                ));
                $em->persist($history);
            });

            // Notification de rejet à l'Agent — priorité haute (commentaire en tête)
            $emetteur = $alert->getEmetteur();
            if ($emetteur) {
                $notificationService->notify(
                    $emetteur,
                    sprintf(
                        'Votre alerte %s a été rejetée par %s. Motif : %s',
                        $alert->getCodeGei() ?? '#' . $alert->getId(),
                        $currentUser instanceof User ? $currentUser->getNomComplet() : 'le Manager',
                        $commentaireRejet
                    ),
                    'rejet',
                    $alert
                );
            }

            $this->addFlash('info', 'Fiche rejetée. L\'Agent doit compléter et resoumettre.');
            return $this->redirectToRoute('app_alert_show', ['token' => $token]);
        }

        // ── Formulaire Agent (qualification) ou Manager (décision via QualificationType) ──
        // Le Manager utilise QualificationType pour pouvoir corriger ponctuellement
        // les critères de scoring avant validation (droit de correction ponctuelle établi).
        // En mode canDecide, les champs critères sont rendus en lecture seule côté template.
        // On capture le score AVANT que handleRequest() ne modifie l'entité via le formulaire.
        $scoreBeforeManagerEdit = $alert->getScoreGei();
        $form = $this->createForm(QualificationType::class, $alert);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if (!$this->isCsrfTokenValid('qualify-' . $alert->getId(), $request->request->get('_token_qualify'))) {
                // Pas de token séparé sur le form_start standard — on ne bloque pas ici
                // (Symfony form CSRF est géré par le framework sur form_start)
            }

            $ancienStatut = $alert->getStatut();

            $em->wrapInTransaction(function () use (
                $alert, $scoreCalculator, $escalationEngine, $em, $canDecide, $scoreBeforeManagerEdit,
                $notificationService, $ancienStatut, $currentUser
            ) {
                $userLabel = $currentUser instanceof User ? $currentUser->getNomComplet() : 'Manager';

                // Gestion Licite vs Illicite
                if ($alert->getLegalStatus() === AlertLegality::LICITE) {
                    $alert->setScoreGei(1);
                    $alert->setNiveauPriorite(NiveauPriorite::FAIBLE);
                    $alert->setFiabiliteSource(null);
                    $alert->setCredibiliteContenu(null);
                    $alert->setUrgence(null);
                    $alert->setImpact(null);
                    $alert->setExploitabilite(null);
                } else {
                    // Calcul du score Annexe C (toujours recalculé à la soumission)
                    $scoreCalculator->calculate($alert);
                }

                if ($scoreBeforeManagerEdit !== $alert->getScoreGei()) {
                    $em->persist($scoreCalculator->createHistoryEntry($alert, $userLabel, $scoreBeforeManagerEdit));
                }

                $history = new AlertStatusHistory();
                $history->setAlert($alert);
                $history->setAncienStatut($ancienStatut);
                $history->setChangedBy($currentUser instanceof User ? $currentUser : null);

                if ($canDecide) {
                    // ── Décision Manager : validation ───────────────────────────────
                    // Le statut est un Backed Enum Doctrine. MethodMarkingStore de Symfony
                    // attend une chaîne et tente de caster l'Enum : transition appliquée
                    // ici après les contrôles d'autorisation et d'état ci-dessus.
                    $alert->setStatut(AlertStatut::VALIDEE);

                    // Traçabilité nominative (Partie B) + horodatage (Partie D)
                    $alert->setValidatedBy($currentUser instanceof User ? $currentUser : null);
                    $alert->setDateValidation(new \DateTime());

                    // Évaluation immédiate de l'escalade (dont Urgence 72h automatique si score élevé ou urgent)
                    $escalationEngine->evaluate($alert);

                    // ── INCRÉMENTATION DU COMPTEUR DE REQUALIFICATIONS ──────────────
                    // La qualification initiale ne consomme pas une requalification.
                    // Les validations suivantes par un Manager (PFT) sont limitées à trois.
                    if ($currentUser instanceof User
                        && $currentUser->getRole() === \App\Enum\UserRoleEnum::PFT
                        && $scoreBeforeManagerEdit !== null) {
                        $alert->incrementRequalificationCount();
                    }

                    $history->setNouveauStatut($alert->getStatut());
                    $history->setTransitionName('valider');
                    $history->setJustification(sprintf(
                        'Validée par %s. Score GEI : %d (%s).',
                        $userLabel,
                        $alert->getScoreGei() ?? 0,
                        $alert->getNiveauPriorite()?->label() ?? 'N/A'
                    ));
                    $em->persist($history);

                    // Notification à l'Agent — validation
                    $emetteur = $alert->getEmetteur();
                    if ($emetteur) {
                        $notificationService->notify(
                            $emetteur,
                            sprintf(
                                'Votre alerte %s a été validée par %s. Score GEI : %d (%s).',
                                $alert->getCodeGei() ?? '#' . $alert->getId(),
                                $userLabel,
                                $alert->getScoreGei() ?? 0,
                                $alert->getNiveauPriorite()?->label() ?? 'N/A'
                            ),
                            'validation',
                            $alert
                        );
                    }

                    // Notification urgente distincte si score critique (Partie H)
                    if (($alert->getScoreGei() ?? 0) >= 18 && $alert->getMarket()) {
                        $notificationService->notifyManagersOfMarket(
                            $alert->getMarket(),
                            sprintf(
                                '🚨 ALERTE CRITIQUE — %s validée avec score %d (%s). Procédure Urgence 72h déclenchée.',
                                $alert->getCodeGei() ?? '#' . $alert->getId(),
                                $alert->getScoreGei() ?? 0,
                                $alert->getNiveauPriorite()?->label() ?? 'N/A'
                            ),
                            'urgence',
                            $alert
                        );
                    }
                }
            });

            $this->addFlash('success', sprintf(
                'Alerte %s avec succès ! Score GEI : %d (%s)',
                'validée',
                $alert->getScoreGei() ?? 0,
                $alert->getNiveauPriorite()?->label() ?? 'N/A'
            ));

            return $this->redirectToRoute('app_alert_show', ['token' => $token]);
        }

        return $this->render('alert/qualification.html.twig', [
            'alert'     => $alert,
            'form'      => $form,
            'canDecide' => $canDecide,
        ]);
    }

    /**
     * Surcharge manuelle du score/priorité par un Manager (avec justification obligatoire).
     * Accessible uniquement via ROLE_PFT ou ROLE_SAHOLTY.
     */
    #[Route('/{token}/override-score', name: 'app_alert_override_score', methods: ['POST'], requirements: ['token' => '[A-Za-z0-9_-]+'])]
    public function overrideScore(
        string $token,
        Request $request,
        AlertRepository $alertRepository,
        EntityManagerInterface $em,
        ScoreCalculatorService $scoreCalculator,
    ): Response
    {
        $alert = $this->resolveAlertToken($token, $alertRepository);
        $this->denyAccessUnlessGranted(AlertVoter::DECIDE, $alert);

        if ($alert->getStatut() !== AlertStatut::VALIDEE) {
            throw $this->createAccessDeniedException("La surcharge d'un score n'est possible qu'après validation Manager.");
        }

        if (!$this->isCsrfTokenValid('override-' . $alert->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Jeton de sécurité invalide.');
            return $this->redirectToRoute('app_alert_qualify', ['token' => $token]);
        }

        $justification = trim((string) $request->request->get('justification_surcharge', ''));

        if ($justification === '') {
            $this->addFlash('danger', 'Une justification est obligatoire pour surcharger manuellement le score/priorité.');
            return $this->redirectToRoute('app_alert_qualify', ['token' => $token]);
        }

        $oldEffectiveScore = $alert->getEffectiveScore();

        /** @var User|null $user */
        $user = $this->getUser();
        if ($user instanceof User) {
            $alert->setSurchargePar($user);
        }

        $score = $request->request->get('score_surcharge');
        if ($score !== null && $score !== '') {
            $scoreInt = (int) $score;
            if ($scoreInt < 1 || $scoreInt > 25) {
                $this->addFlash('danger', 'Le score surchargé doit être compris entre 1 et 25.');
                return $this->redirectToRoute('app_alert_qualify', ['token' => $token]);
            }
            $alert->setScoreSurcharge($scoreInt);
        } else {
            $alert->setScoreSurcharge(null);
        }

        $prioriteVal = $request->request->get('priorite_surcharge');
        if (!empty($prioriteVal)) {
            try {
                $alert->setNiveauPrioriteSurcharge(NiveauPriorite::from($prioriteVal));
            } catch (\ValueError) {
                $this->addFlash('danger', 'Niveau de priorité invalide.');
                return $this->redirectToRoute('app_alert_qualify', ['token' => $token]);
            }
        } else {
            $alert->setNiveauPrioriteSurcharge(null);
        }

        $alert->setJustificationSurcharge($justification);
        $newEffectiveScore = $alert->getEffectiveScore();
        if ($oldEffectiveScore !== $newEffectiveScore) {
            $em->persist($scoreCalculator->createHistoryEntry(
                $alert,
                $user instanceof User ? $user->getNomComplet() : 'Manager',
                $oldEffectiveScore,
                $newEffectiveScore,
            ));
        }
        $em->flush();

        $this->addFlash('success', 'Surcharge manuelle enregistrée.');
        return $this->redirectToRoute('app_alert_show', ['token' => $token]);
    }

    /**
     * Page dédiée affichée quand un Manager a atteint la limite de 3 requalifications.
     * Accessible uniquement via token opaque (ID non devinable).
     */
    #[Route('/{token}/requalification-limit', name: 'app_alert_requalification_limit', methods: ['GET'], requirements: ['token' => '[A-Za-z0-9_-]+'])]
    public function requalificationLimit(
        string $token,
        AlertRepository $alertRepository,
    ): Response {
        $alert = $this->resolveAlertToken($token, $alertRepository);

        /** @var User|null $currentUser */
        $currentUser = $this->getUser();

        // Si l'utilisateur peut encore requalifier, on le redirige vers la qualification normale
        if ($currentUser instanceof User && $alert->canBeRequalifiedBy($currentUser)) {
            return $this->redirectToRoute('app_alert_qualify', ['token' => $token]);
        }

        return $this->render('alert/requalification_limit.html.twig', [
            'alert'      => $alert,
            'alertToken' => $token,
            'count'      => $alert->getRequalificationCount(),
        ]);
    }

    /**
     * Endpoint JSON — calcul live du score pour le widget côté client.
     * Valide strictement toutes les entrées avant de les passer au service.
     */
    #[Route('/api/preview-score', name: 'app_alert_preview_score', methods: ['POST'])]
    public function previewScore(Request $request, ScoreCalculatorService $scoreCalculator): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return new JsonResponse(['error' => 'Corps de requête JSON invalide.'], 400);
        }

        try {
            $legalStatus = null;
            if (!empty($data['legalStatus'])) {
                $legalStatus = AlertLegality::tryFrom((string) $data['legalStatus']);
            }

            $result = $scoreCalculator->computeFromValues(
                fiabilite:     $data['fiabilite']     ?? 'C',
                credibilite:   (int) ($data['credibilite']   ?? 3),
                urgence:       $data['urgence']       ?? 'routine',
                impact:        $data['impact']        ?? 'moyen',
                exploitabilite: $data['exploitabilite'] ?? 'a_completer',
                legalStatus:   $legalStatus,
            );

            return new JsonResponse($result);
        } catch (\ValueError $e) {
            return new JsonResponse(['error' => 'Valeur invalide : ' . $e->getMessage()], 400);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => 'Erreur de calcul.'], 500);
        }
    }
}
