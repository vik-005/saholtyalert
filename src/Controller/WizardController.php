<?php

namespace App\Controller;

use App\Entity\Alert;
use App\Entity\AlertComment;
use App\Entity\ListeReferenceValeur;
use App\Enum\AlertExploitabilite;
use App\Enum\AlertImpact;
use App\Enum\AlertStatut;
use App\Enum\AlertUrgence;
use App\Enum\FiabiliteSource;
use App\Enum\Recommandation;
use App\Enum\TypeAlerte;
use App\Repository\ListeReferenceValeurRepository;
use App\Repository\MarketRepository;
use App\Service\AlertCodeGeneratorService;
use App\Service\ScoreCalculatorService;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Wizard multi-étapes pour la création d'alerte par l'Agent.
 *
 * Formulaire officiel — 20 champs (note manuscrite de référence) :
 *   Étape 1 — Identification         : champs 1 (date), 2 (marché), 3+4 (port/corridor + nom)
 *   Étape 2 — Contenu de l'alerte    : champs 5 (réf. doc), 6 (description/quantité), 7 (marque), 8 (résumé)
 *   Étape 3 — Classification         : champs 9 (catégorie), 10 (type d'alerte)
 *   Étape 4 — Source                 : champs 11 (type de source), 12 (anonymisation)
 *   Étape 5 — Qualification          : champs 13 (fiabilité), 14 (crédibilité), 15 (urgence), 16 (impact), 17 (exploitabilité)
 *   Étape 6 — Relecture & soumission : récapitulatif + commentaire + soumission
 *
 * Champ ID Alerte : généré par le système à la soumission (GEI-{ISO3}-{ANNEE}-{SEQ:003}).
 * Champ 18 (Décision GEI) : réservé au Manager.
 * Champ 19 (Statut) : piloté par le workflow système.
 * Champ 20 (Commentaires/Suivi) : alimentation cumulative Agent/Manager, historisée.
 */
#[Route('/wizard')]
#[IsGranted('ROLE_USER')]
class WizardController extends AbstractController
{
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly ScoreCalculatorService $scoreCalculator,
        private readonly AlertCodeGeneratorService $codeGenerator,
        private readonly MarketRepository $marketRepository,
        private readonly ListeReferenceValeurRepository $listeReferenceRepository,
        private readonly NotificationService $notificationService,
    ) {}

    #[Route('/new', name: 'app_wizard_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $user = $this->getUser();
        if (!$user instanceof \App\Entity\User) {
            throw $this->createAccessDeniedException();
        }

        $currentStep = max(1, min(6, (int) $request->query->get('step', 1)));
        $draftData   = $this->getDraftData($user->getId());

        // Vérifier si on est en édition d'une alerte existante
        $editingId = $request->getSession()->get("alert_edit_id_{$user->getId()}");
        if ($editingId) {
            $alert = $em->getRepository(Alert::class)->find($editingId);
            if (!$alert) {
                $alert = new Alert();
                $alert->setEmetteur($user);
                if ($user->getMarket()) {
                    $alert->setMarket($user->getMarket());
                }
                $request->getSession()->remove("alert_edit_id_{$user->getId()}");
            }
        } else {
            $alert = new Alert();
            $alert->setEmetteur($user);
            if ($user->getMarket()) {
                $alert->setMarket($user->getMarket());
            }
        }

        if ($draftData) {
            $this->hydrateAlertFromDraft($alert, $draftData, $em);
        }

        // Gestion du POST (navigation entre étapes)
        if ($request->isMethod('POST')) {
            $formData = $request->request->all();
            $action   = $request->request->get('_action', 'next');

            // Validation côté serveur par étape
            $alertData = $formData['alert'] ?? $formData;
            $validationError = $this->validateStep($currentStep, $alertData);
            if ($validationError) {
                $this->addFlash('error', $validationError);
                return $this->redirectToRoute('app_wizard_new', ['step' => $currentStep]);
            }

            // Sauvegarder le draft à chaque étape
            $this->saveDraft($user->getId(), $formData);

            if ($action === 'prev') {
                return $this->redirectToRoute('app_wizard_new', ['step' => max(1, $currentStep - 1)]);
            }

            if ($action === 'next' && $currentStep < 6) {
                return $this->redirectToRoute('app_wizard_new', ['step' => $currentStep + 1]);
            }

            if ($action === 'submit' || ($action === 'next' && $currentStep === 6)) {
                return $this->submit($formData, $alert, $em, $user);
            }
        }

        $markets = $this->marketRepository->findBy(['actif' => true], ['nom' => 'ASC']);

        $sharedVars = [
            'alert'         => $alert,
            'draftData'     => $draftData ?? [],
            'currentStep'   => $currentStep,
            'totalSteps'    => 6,
            'markets'       => $markets,
            'categories'    => $this->listeReferenceRepository->findActivesByType('categorie'),
            'typeAlertes'   => TypeAlerte::cases(),
            'typeSources'   => $this->listeReferenceRepository->findActivesByType('type_source'),
            'fiabilites'    => FiabiliteSource::cases(),
            'urgences'      => AlertUrgence::cases(),
            'impacts'       => AlertImpact::cases(),
            'exploitabilites'=> AlertExploitabilite::cases(),
            'recommandations'=> Recommandation::cases(),
        ];

        return $this->render('wizard/new.html.twig', $sharedVars);
    }

    #[Route('/score/preview', name: 'app_wizard_score_preview', methods: ['POST'])]
    public function scorePreview(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? $request->request->all();

        try {
            $result = $this->scoreCalculator->computeFromValues(
                fiabilite:      $data['fiabiliteSource'] ?? 'C',
                credibilite:    (int)($data['credibiliteContenu'] ?? 3),
                urgence:        $data['urgence'] ?? 'routine',
                impact:         $data['impact'] ?? 'moyen',
                exploitabilite: $data['exploitabilite'] ?? 'a_completer',
            );

            return new JsonResponse($result);
        } catch (\ValueError $e) {
            return new JsonResponse(['error' => 'Valeur invalide : ' . $e->getMessage()], 400);
        } catch (\Exception $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }
    }

    #[Route('/draft/clear', name: 'app_wizard_clear_draft', methods: ['POST'])]
    public function clearDraft(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof \App\Entity\User) {
            return new JsonResponse(['error' => 'Non autorisé'], 403);
        }

        $this->clearDraftData($user->getId());

        return new JsonResponse(['success' => true]);
    }

    // ── Validation par étape ─────────────────────────────────────────────────

    private function validateStep(int $step, array $data): ?string
    {
        return match ($step) {
            1 => $this->validateStep1($data),
            2 => $this->validateStep2($data),
            3 => $this->validateStep3($data),
            4 => $this->validateStep4($data),
            5 => $this->validateStep5($data),
            6 => null, // Récapitulatif : pas de validation métier
            default => null,
        };
    }

    private function validateStep1(array $data): ?string
    {
        if (empty($data['dateCreation'])) {
            return 'La date de réception de l\'alerte est obligatoire.';
        }
        if (empty($data['market'])) {
            return 'Le marché émetteur est obligatoire.';
        }
        if (empty($data['typeLocalisation'])) {
            return 'Le choix Port / Corridor / Aéroport est obligatoire.';
        }
        if (!in_array($data['typeLocalisation'], ['port', 'corridor', 'aeroport'], true)) {
            return 'Le type de localisation doit être Port, Corridor ou Aéroport.';
        }
        if (empty($data['portCorridor'])) {
            return 'Le nom du port ou corridor est obligatoire.';
        }
        return null;
    }

    private function validateStep2(array $data): ?string
    {
        if (empty($data['elementsFactuels'])) {
            return 'La description / quantité est obligatoire.';
        }
        if (empty($data['resumeExecutif'])) {
            return 'Le résumé de l\'information est obligatoire.';
        }
        if (mb_strlen($data['resumeExecutif']) > 500) {
            return 'Le résumé ne peut pas dépasser 500 caractères.';
        }
        return null;
    }

    private function validateStep3(array $data): ?string
    {
        if (empty($data['categorie'])) {
            return 'La catégorie de l\'alerte est obligatoire.';
        }
        if (empty($data['typeAlerte'])) {
            return 'Le type d\'alerte (Opérationnelle / Stratégique) est obligatoire.';
        }
        if (!in_array($data['typeAlerte'], ['operationnelle', 'strategique'], true)) {
            return 'Le type d\'alerte sélectionné n\'est pas valide.';
        }
        return null;
    }

    private function validateStep4(array $data): ?string
    {
        if (empty($data['typeSource'])) {
            return 'Le type de source est obligatoire.';
        }
        $anon = $data['anonymisation'] ?? '';
        if (!in_array($anon, ['oui', 'non'], true)) {
            return 'Le niveau d\'anonymisation (Oui / Non) est obligatoire.';
        }
        return null;
    }

    private function validateStep5(array $data): ?string
    {
        $fiab = $data['fiabiliteSource'] ?? '';
        if (empty($fiab) || !in_array($fiab, ['A', 'B', 'C', 'D'], true)) {
            return 'La fiabilité source doit être A, B, C ou D.';
        }
        $cred = $data['credibiliteContenu'] ?? '';
        if ($cred === '' || !in_array((int)$cred, [1, 2, 3, 4], true)) {
            return 'La crédibilité doit être comprise entre 1 et 4.';
        }
        $urg = $data['urgence'] ?? '';
        if (empty($urg) || !in_array($urg, ['immediat', '72h', 'routine'], true)) {
            return 'L\'urgence doit être Immédiat, 72h ou Routine.';
        }
        $imp = $data['impact'] ?? '';
        if (empty($imp) || !in_array($imp, ['eleve', 'moyen', 'faible'], true)) {
            return 'L\'impact doit être Élevé, Moyen ou Faible.';
        }
        $exp = $data['exploitabilite'] ?? '';
        if (empty($exp) || !in_array($exp, ['actionnable', 'exploitable', 'a_analyser', 'a_surveiller', 'a_completer', 'archivage'], true)) {
            return 'L\'exploitabilité sélectionnée n\'est pas valide.';
        }
        return null;
    }

    // ── Submit final ────────────────────────────────────────────────────────

    private function submit(array $formData, Alert $alert, EntityManagerInterface $em, \App\Entity\User $user): Response
    {
        $session = $this->requestStack->getCurrentRequest()?->getSession();
        $editingId = $session?->get("alert_edit_id_{$user->getId()}");

        $isEdit = false;
        $previousStatut = AlertStatut::NOUVEAU;
        $existingAlert = null; // initialisé ici — défini plus bas uniquement en mode édition
        if ($editingId) {
            $existingAlert = $em->getRepository(Alert::class)->find($editingId);
            if ($existingAlert) {
                $alert = $existingAlert;
                $isEdit = true;
                $previousStatut = $existingAlert->getStatut();
            }
        }

        // Capture du brouillon (format alert[key])
        $draftData = $this->getDraftData($user->getId());
        $commentaireSoumission = trim($formData['alert']['commentaires'] ?? $draftData['alert[commentaires]'] ?? '');

        // Exécuter tout le processus de soumission dans une transaction
        $em->wrapInTransaction(function () use (
            $alert, $formData, $draftData, $em, $user, $isEdit, $previousStatut, $commentaireSoumission, $existingAlert
        ): void {
            // Hydration complète en fusionnant le brouillon session et les données du formulaire POST
            $mergedData = array_merge($draftData ?? [], $formData);
            $this->hydrateAlertFromDraft($alert, $mergedData, $em);

            // Sécurité absolue contre colonnes nulles en base
            if (empty($alert->getResumeExecutif())) {
                $alert->setResumeExecutif($draftData['alert[resumeExecutif]'] ?? $formData['alert']['resumeExecutif'] ?? 'Fiche alerte');
            }
            if (empty($alert->getElementsFactuels())) {
                $alert->setElementsFactuels($draftData['alert[elementsFactuels]'] ?? $formData['alert']['elementsFactuels'] ?? 'Non spécifié');
            }

            if (!$isEdit) {
                $alert->setEmetteur($user);
                if (!$alert->getMarket() && $user->getMarket()) {
                    $alert->setMarket($user->getMarket());
                }
            }

            // Calculer le score (calculé côté serveur avant persistance — jamais de confiance sur JS)
            $this->scoreCalculator->calculate($alert);

            // Statut : soumis au Manager
            $alert->setStatut(AlertStatut::A_VALIDER_SAHOLTY);

            // Générer le code GEI (format préservé : GEI-{ISO3}-{ANNEE}-{SEQ:003})
            if (null === $alert->getCodeGei() && null !== $alert->getMarket()) {
                $alert->setCodeGei($this->codeGenerator->generate($alert));
            }

            $em->persist($alert);

            // Historique qualification (snapshot complet des critères)
            $history = $this->scoreCalculator->createHistoryEntry($alert, $user->getNomComplet());
            $em->persist($history);

            // Historique de statut (traçabilité — qui, quand, pourquoi)
            $statusHistory = new \App\Entity\AlertStatusHistory();
            $statusHistory->setAlert($alert);
            $statusHistory->setAncienStatut($previousStatut);
            $statusHistory->setNouveauStatut(AlertStatut::A_VALIDER_SAHOLTY);
            $statusHistory->setChangedBy($user);
            $statusHistory->setTransitionName($isEdit ? 'resoumettre' : 'soumettre_validation');
            $em->persist($statusHistory);

            // Commentaire à la soumission (champ 20 — première ligne historisée)
            if ($commentaireSoumission !== '') {
                $comment = new AlertComment();
                $comment->setAlert($alert);
                $comment->setAuteur($user);
                $comment->setContenu($commentaireSoumission);
                $comment->setType('agent');
                $alert->addCommentaireHistorise($comment);
                $em->persist($comment);
            }

            // Log de création / modification
            $log = new \App\Entity\AccessLog();
            $log->setUser($user);
            $log->setAlert($alert);
            $log->setAction($isEdit ? \App\Entity\AccessLog::ACTION_MODIFICATION : \App\Entity\AccessLog::ACTION_CREATION);
            $log->setIpAdresse($this->requestStack->getCurrentRequest()?->getClientIp());
            $log->setResultat('success');
            $log->setDetails(($isEdit ? 'Alerte modifiée' : 'Alerte créée') . ' via wizard par ' . $user->getNomComplet());

            if ($isEdit && isset($existingAlert)) {
                $before = $this->snapshotAlertState($existingAlert);
                $after = $this->snapshotAlertState($alert);
                if ($before !== $after) {
                    $log->setDiffData(['before' => $before, 'after' => $after]);
                }
            }

            $em->persist($log);

            // Créer les notifications des managers (persistées mais pas de flush séparé)
            if ($alert->getMarket()) {
                $this->createManagerNotifications($alert, $em, $isEdit, $user);
            }
        });

        // Flush unique à la fin de la transaction
        $em->flush();

        // Supprimer le draft et l'id d'édition (hors transaction — session seulement)
        $this->clearDraftData($user->getId());
        $session?->remove("alert_edit_id_{$user->getId()}");

        $this->addFlash('success', sprintf('Alerte %s soumise avec succès.', $alert->getCodeGei()));

        return $this->redirectToRoute('app_alert_show', ['id' => $alert->getId()]);
    }

    /**
     * Crée les notifications pour les managers du marché concerné.
     * Les entités sont persistées mais pas flushées — le flush global viendra
     * dans la transaction parente.
     */
    private function createManagerNotifications(Alert $alert, EntityManagerInterface $em, bool $isEdit, \App\Entity\User $agent): void
    {
        $markets = $alert->getMarket() ? [$alert->getMarket()] : [];

        // PFT managers + SAHOLTY (tous) + SUPERADMIN — via la même logique que notifyManagersOfMarket
        $qb = $em->createQueryBuilder();
        $qb->select('u')
            ->from(\App\Entity\User::class, 'u')
            ->where('u.role IN (:roles)')
            ->setParameter('roles', [\App\Enum\UserRoleEnum::PFT->value, \App\Enum\UserRoleEnum::SAHOLTY->value]);

        $managers = $qb->getQuery()->getResult();

        $score = $alert->getScoreGei() ?? 0;
        $priorite = $alert->getNiveauPriorite()?->label() ?? 'Non calculé';

        foreach ($managers as $manager) {
            /** @var \App\Entity\User $manager */
            $isSaholty = $manager->getRole() === \App\Enum\UserRoleEnum::SAHOLTY;
            $managesMarket = in_array($alert->getMarket(), $manager->getAllManagedMarkets(), true);

            if ($isSaholty || $managesMarket) {
                // Anti-duplication : ne pas recréer une notification identique dans les 10 dernières minutes
                if ($alert->getId() !== null) {
                    $existing = $this->notificationService->findExistingRecent(
                        $manager, 'new_alert', $alert
                    );
                    if ($existing) {
                        continue;
                    }
                }

                $notif = new \App\Entity\Notification();
                $notif->setDestinataire($manager);
                $notif->setAlert($alert);
                $notif->setType('new_alert');
                $notif->setContenu(sprintf(
                    '%s' . PHP_EOL .
                    'Code GEI : %s' . PHP_EOL .
                    'Marché : %s' . PHP_EOL .
                    'Score GEI : %d/25 (%s)' . PHP_EOL .
                    'Résumé : %s' . PHP_EOL .
                    'Agent : %s',
                    $isEdit ? 'Alerte modifiée et resoupée' : 'Nouvelle alerte soumise',
                    $alert->getCodeGei() ?? 'BROUILLON',
                    $alert->getMarket()->getNom(),
                    $score,
                    $priorite,
                    mb_strimwidth($alert->getResumeExecutif() ?? '', 0, 80, '...'),
                    $agent->getNomComplet()
                ));
                $em->persist($notif);
            }
        }
    }

    // ── Helpers Draft ────────────────────────────────────────────────────────

    private function getDraftData(int $userId): ?array
    {
        $session = $this->requestStack->getCurrentRequest()?->getSession();
        return $session?->get("alert_draft_{$userId}");
    }

    private function saveDraft(int $userId, array $data): void
    {
        unset($data['_token'], $data['_action'], $data['wizard_step']);

        if (isset($data['alert']) && is_array($data['alert'])) {
            foreach ($data['alert'] as $key => $value) {
                $data["alert[{$key}]"] = $value;
            }
            // CRITICAL: remove the 'alert' array so array_merge doesn't
            // overwrite accumulated alert[key] entries from previous steps
            unset($data['alert']);
        }

        $session = $this->requestStack->getCurrentRequest()?->getSession();
        $existing = (array) $session?->get("alert_draft_{$userId}", []);

        // Merge intelligent : ne jamais écraser une valeur existante non-vide
        // par une valeur vide provenant d'un champ caché absent ou vide (ex. step6 hidden inputs).
        $merged = $existing;
        foreach ($data as $key => $value) {
            $currentValue = $existing[$key] ?? null;
            // Accepter la nouvelle valeur si : pas encore de valeur, ou nouvelle valeur non-vide
            if ($currentValue === null || $currentValue === '' || ($value !== null && $value !== '')) {
                $merged[$key] = $value;
            }
        }

        $session?->set("alert_draft_{$userId}", $merged);
    }

    private function clearDraftData(int $userId): void
    {
        $this->requestStack->getCurrentRequest()?->getSession()?->remove("alert_draft_{$userId}");
    }

    /**
     * Capture un instantané des champs modifiables d'une alerte
     * pour le journal d'activité (avant/après).
     */
    private function snapshotAlertState(Alert $alert): array
    {
        return [
            'Marché'         => $alert->getMarket()?->getNom(),
            'Catégorie'      => $alert->getCategorie(),
            'Type alerte'    => $alert->getTypeAlerte()?->value,
            'Type source'    => $alert->getTypeSource(),
            'Fiabilité'      => $alert->getFiabiliteSource()?->value,
            'Crédibilité'    => $alert->getCredibiliteContenu() ? $alert->getCredibiliteContenu() . '/4' : null,
            'Urgence'        => $alert->getUrgence()?->label(),
            'Impact'         => $alert->getImpact()?->label(),
            'Exploitabilité' => $alert->getExploitabilite()?->label(),
            'Recommandation' => $alert->getRecommandation()?->label(),
            'Score'          => $alert->getScoreGei(),
        ];
    }

    private function hydrateAlertFromDraft(Alert $alert, array $data, EntityManagerInterface $em): void
    {
        // Normalize draft data: support both "alert[key]" (wizard) and "key" (edit) formats
        $alertData = isset($data['alert']) && is_array($data['alert']) ? $data['alert'] : [];

        foreach ($data as $key => $value) {
            if (str_starts_with($key, 'alert[') && str_ends_with($key, ']')) {
                $field = substr($key, 6, -1); // Extract field name from "alert[field]"
                if (!isset($alertData[$field]) || $alertData[$field] === null || $alertData[$field] === '') {
                    $alertData[$field] = $value;
                }
            } elseif (!str_contains($key, '[')) {
                // Flat key format (from convertAlertToDraftData)
                if (!isset($alertData[$key]) || $alertData[$key] === null || $alertData[$key] === '') {
                    $alertData[$key] = $value;
                }
            }
        }

        // Date de création
        if (!empty($alertData['dateCreation'])) {
            $date = \DateTime::createFromFormat('Y-m-d', $alertData['dateCreation']);
            if ($date) {
                $alert->setDateCreation($date);
            }
        }

        // Market
        if (!empty($alertData['market'])) {
            $market = $em->find(\App\Entity\Market::class, (int) $alertData['market']);
            if ($market) {
                $alert->setMarket($market);
            }
        }

        // Champs texte simples
        // Les champs obligatoires (resumeExecutif, elementsFactuels) sont mis à jour dès qu'ils ont une valeur.
        // Les champs optionnels sont mis à jour seulement si non vides (évite d'écraser par null).
        $champsObligatoires = ['resumeExecutif', 'elementsFactuels', 'portCorridor'];
        foreach (['portCorridor', 'resumeExecutif', 'elementsFactuels', 'marque', 'operateurActeur', 'hypothesesAnalytiques', 'referenceDocumentaire', 'historiqueSource', 'actionsEnCours', 'commentaires'] as $field) {
            $setter = 'set' . ucfirst($field);
            if (!method_exists($alert, $setter)) {
                continue;
            }
            // Use array_key_exists (not !empty) so empty strings are still set on the entity.
            // An empty string is NOT NULL and will pass the DB NOT NULL constraint.
            // Validation should have rejected empty mandatory fields before submission.
            if (array_key_exists($field, $alertData)) {
                $alert->$setter($alertData[$field]);
            }
        }

        // Champs string (catégorie, type source, anonymisation — depuis listes/fixes)
        if (!empty($alertData['categorie'])) {
            $alert->setCategorie($alertData['categorie']);
        }
        if (!empty($alertData['typeSource'])) {
            $alert->setTypeSource($alertData['typeSource']);
        }
        if (!empty($alertData['anonymisation'])) {
            $alert->setAnonymisation($alertData['anonymisation']);
        }

        // Enums
        $enumMap = [
            'typeLocalisation'=> [\App\Enum\TypeLocalisation::class, 'setTypeLocalisation'],
            'typeAlerte'      => [\App\Enum\TypeAlerte::class, 'setTypeAlerte'],
            'fiabiliteSource' => [\App\Enum\FiabiliteSource::class, 'setFiabiliteSource'],
            'urgence'         => [\App\Enum\AlertUrgence::class, 'setUrgence'],
            'impact'          => [\App\Enum\AlertImpact::class, 'setImpact'],
            'exploitabilite'  => [\App\Enum\AlertExploitabilite::class, 'setExploitabilite'],
            'recommandation'  => [\App\Enum\Recommandation::class, 'setRecommandation'],
        ];

        foreach ($enumMap as $field => [$enumClass, $setter]) {
            if (!empty($alertData[$field])) {
                try {
                    $enum = $enumClass::from($alertData[$field]);
                    $alert->$setter($enum);
                } catch (\ValueError) {
                    // Valeur invalide ignorée silencieusement
                }
            }
        }

        // Crédibilité (int)
        if (!empty($alertData['credibiliteContenu'])) {
            $alert->setCredibiliteContenu((int) $alertData['credibiliteContenu']);
        }
    }
}
