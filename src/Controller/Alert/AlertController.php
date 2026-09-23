<?php

namespace App\Controller\Alert;

use App\Entity\AccessLog;
use App\Entity\Alert;
use App\Repository\AlertRepository;
use App\Repository\MarketRepository;
use App\Service\StatistiquesService;
use App\Voter\AlertVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Intl\Countries;

#[Route('/alert')]
#[IsGranted('ROLE_USER')]
class AlertController extends AbstractController
{
    public function __construct(
        private readonly RequestStack $requestStack,
    ) {}

    #[Route('/', name: 'app_alert_index', methods: ['GET'])]
    public function index(Request $request, AlertRepository $alertRepository, MarketRepository $marketRepository): Response
    {
        $user = $this->getUser();
        if (!$user instanceof \App\Entity\User) {
            throw $this->createAccessDeniedException();
        }

        $marketFilter = $request->query->get('market');
        if (is_array($marketFilter)) {
            $marketFilter = reset($marketFilter);
        }

        $filters = [
            'statut'         => $request->query->get('statut'),
            'niveauPriorite' => $request->query->get('priorite'),
            'typeAlerte'     => $request->query->get('typeAlerte'),
            'market'         => $marketFilter,
            'search'         => $request->query->get('search'),
            'categorie'      => $request->query->get('categorie'),
            'urgence'        => $request->query->get('urgence'),
            'scoreMin'       => $request->query->get('scoreMin'),
            'scoreMax'       => $request->query->get('scoreMax'),
            'origine'        => $request->query->get('origine'),
            'dateDebut'      => $request->query->get('dateDebut'),
            'dateFin'        => $request->query->get('dateFin'),
            'anneeOperationnelle' => $request->query->get('anneeOperationnelle'),
            'agent'          => $request->query->get('agent'),   // Partie B
            'manager'        => $request->query->get('manager'), // Partie B
        ];

        $page = max(1, (int) $request->query->get('page', 1));
        $limit = 25;
        $allMatchingAlerts = $alertRepository->findForUser($user, $filters);
        $total = count($allMatchingAlerts);
        $totalPages = max(1, (int) ceil($total / $limit));
        $page = min($page, $totalPages);
        $alerts = $alertRepository->findForUser($user, $filters, $page, $limit);

        // Peuplement des selects Agent/Manager pour les filtres (Partie B)
        $agentsForFilter   = $alertRepository->findAgentsForFilter($user);
        $managersForFilter = $alertRepository->findManagersForFilter($user);

        return $this->render('alert/index.html.twig', [
            'alerts'           => $alerts,
            'filters'          => $filters,
            'total'            => $total,
            'page'             => $page,
            'totalPages'       => $totalPages,
            'agentsForFilter'  => $agentsForFilter,
            'managersForFilter' => $managersForFilter,
            'marketsForFilter' => $marketRepository->findBy([], ['nom' => 'ASC']),
        ]);
    }

    #[Route('/new', name: 'app_alert_new', methods: ['GET', 'POST'])]
    public function new(): Response
    {
        return $this->redirectToRoute('app_wizard_new');
    }

    #[Route('/{id}', name: 'app_alert_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(Alert $alert, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted(AlertVoter::VIEW, $alert);

        // Log de consultation avec flush dédié
        $this->logAction($alert, AccessLog::ACTION_LECTURE, 'Consultation fiche ' . ($alert->getCodeGei() ?? '#' . $alert->getId()), $em);
        $em->flush();

        return $this->render('alert/show.html.twig', [
            'alert' => $alert,
            'countryNames' => Countries::getNames('fr'),
        ]);
    }

    #[Route('/{id}/edit', name: 'app_alert_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, Alert $alert): Response
    {
        $this->denyAccessUnlessGranted(AlertVoter::EDIT_COLLECTE, $alert);

        $user = $this->getUser();
        if (!$user instanceof \App\Entity\User) {
            throw $this->createAccessDeniedException();
        }

        // Convert alert to draft data format and store in session
        $draftData = $this->convertAlertToDraftData($alert);
        $request->getSession()->set("alert_draft_{$user->getId()}", $draftData);
        $request->getSession()->set("alert_edit_id_{$user->getId()}", $alert->getId());

        return $this->redirectToRoute('app_wizard_new', ['step' => 1]);
    }

    private function convertAlertToDraftData(Alert $alert): array
    {
        $data = [
            'market' => $alert->getMarket()?->getId(),
            'portCorridor' => $alert->getPortCorridor(),
            'resumeExecutif' => $alert->getResumeExecutif(),
            'elementsFactuels' => $alert->getElementsFactuels(),
            'hypothesesAnalytiques' => $alert->getHypothesesAnalytiques(),
            'referenceDocumentaire' => $alert->getReferenceDocumentaire(),
            'historiqueSource' => $alert->getHistoriqueSource(),
            'actionsEnCours' => $alert->getActionsEnCours(),
            'commentaires' => $alert->getCommentaires(),
            'categorie' => $alert->getCategorie(),
            'typeAlerte' => $alert->getTypeAlerte()?->value,
            'typeSource' => $alert->getTypeSource(),
            'anonymisation' => $alert->getAnonymisation(),
            'fiabiliteSource' => $alert->getFiabiliteSource()?->value,
            'urgence' => $alert->getUrgence()?->value,
            'impact' => $alert->getImpact()?->value,
            'exploitabilite' => $alert->getExploitabilite()?->value,
            'recommandation' => $alert->getRecommandation()?->value,
            'credibiliteContenu' => $alert->getCredibiliteContenu(),
        ];

        // Also add the template keys
        foreach ($data as $key => $value) {
            $data["alert[{$key}]"] = $value;
        }

        return $data;
    }

    #[Route('/{id}/delete', name: 'app_alert_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Request $request, Alert $alert, EntityManagerInterface $em, StatistiquesService $statistiquesService): Response
    {
        $this->denyAccessUnlessGranted(AlertVoter::DECIDE, $alert);

        if (!$this->isCsrfTokenValid('delete', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Jeton CSRF invalide.');
            return $this->redirectToRoute('app_alert_show', ['id' => $alert->getId()]);
        }

        $codeGei = $alert->getCodeGei() ?? '#' . $alert->getId();
        $user    = $this->getUser();
        $motif   = $request->request->get('motif_suppression', 'Aucun motif précisé');

        // Soft-delete
        $alert->setDeletedAt(new \DateTime());

        // Persist log avant le flush global
        $details = sprintf(
            'Suppression (soft) de %s par %s — Motif : %s',
            $codeGei,
            $user instanceof \App\Entity\User ? $user->getNomComplet() : 'inconnu',
            $motif
        );
        $this->logAction($alert, AccessLog::ACTION_SUPPRESSION, $details, $em);

        $em->flush(); // Un seul flush pour soft-delete + log
        $statistiquesService->invalidateAll();

        $this->addFlash('info', sprintf('Alerte %s archivée (soft-delete).', $codeGei));

        return $this->redirectToRoute('app_alert_index');
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Persiste un AccessLog SANS flush immédiat.
     * Le flush sera fait par le prochain appel applicatif.
     * Appeler flushLog() explicitement si on a besoin d'une persistance garantie.
     */
    private function logAction(?Alert $alert, string $action, string $details, EntityManagerInterface $em): void
    {
        $user    = $this->getUser();
        $request = $this->requestStack->getCurrentRequest();

        if (!$user instanceof \App\Entity\User || !$request) {
            return;
        }

        $log = new AccessLog();
        $log->setUser($user);
        $log->setAlert($alert);
        $log->setAction($action);
        $log->setIpAdresse($request->getClientIp());
        $log->setUserAgent($request->headers->get('User-Agent'));
        $log->setResultat('success');
        $log->setDetails($details);

        // persist() seulement — PAS de flush() ici.
        // Un flush ici déclencherait onFlush sur toutes les alertes en mémoire,
        // provoquant une boucle infinie via AlertScoreSubscriber.
        $em->persist($log);
    }
}
