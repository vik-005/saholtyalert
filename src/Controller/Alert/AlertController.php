<?php

namespace App\Controller\Alert;

use App\Entity\AccessLog;
use App\Entity\Alert;
use App\Entity\AlertAttachment;
use App\Repository\AlertRepository;
use App\Repository\MarketRepository;
use App\Service\AlertAttachmentStorageService;
use App\Service\CryptoService;
use App\Service\StatistiquesService;
use App\Voter\AlertVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Intl\Countries;

#[Route('/alert')]
#[IsGranted('ROLE_USER')]
class AlertController extends AbstractController
{
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly CryptoService $crypto,
    ) {}

    // ── Helper : résolution du token → entité Alert ─────────────────────────

    /**
     * Déchiffre un token URL-safe en ID d'alerte, charge et retourne l'entité.
     * Lance une 404 si le token est invalide, altéré ou si l'entité est introuvable.
     */
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

    /**
     * Consultation d'une fiche alerte via token opaque (ID non devinable).
     * Le token est chiffré avec libsodium (XSalsa20-Poly1305) et lié au contexte 'alert'.
     */
    #[Route('/{token}', name: 'app_alert_show', methods: ['GET'], requirements: ['token' => '[A-Za-z0-9_-]+'])]
    public function show(string $token, AlertRepository $alertRepository, EntityManagerInterface $em): Response
    {
        $alert = $this->resolveAlertToken($token, $alertRepository);
        $this->denyAccessUnlessGranted(AlertVoter::VIEW, $alert);

        // Log de consultation avec flush dédié
        $this->logAction($alert, AccessLog::ACTION_LECTURE, 'Consultation fiche ' . ($alert->getCodeGei() ?? '#' . $alert->getId()), $em);
        $em->flush();

        return $this->render('alert/show.html.twig', [
            'alert'        => $alert,
            'countryNames' => Countries::getNames('fr'),
        ]);
    }

    /**
     * Téléchargement d'une pièce jointe via token opaque d'alerte + token opaque de pièce jointe.
     * Double validation : le token d'alerte doit correspondre à l'alerte parente de la pièce jointe.
     */
    #[Route('/{token}/piece-jointe/{attachToken}', name: 'app_alert_attachment_download', methods: ['GET'], requirements: ['token' => '[A-Za-z0-9_-]+', 'attachToken' => '[A-Za-z0-9_-]+'])]
    public function downloadAttachment(
        string $token,
        string $attachToken,
        AlertRepository $alertRepository,
        EntityManagerInterface $em,
        AlertAttachmentStorageService $attachmentStorage,
    ): Response {
        $alert = $this->resolveAlertToken($token, $alertRepository);
        $this->denyAccessUnlessGranted(AlertVoter::VIEW, $alert);

        // Déchiffrer le token de pièce jointe
        $attachmentId = $this->crypto->decryptId($attachToken, 'attachment');
        if ($attachmentId === null) {
            throw $this->createNotFoundException('Justificatif introuvable.');
        }

        $attachment = $em->getRepository(AlertAttachment::class)->find($attachmentId);
        if (!$attachment instanceof AlertAttachment || $attachment->getAlert()?->getId() !== $alert->getId()) {
            throw $this->createNotFoundException('Justificatif introuvable.');
        }

        $path = $attachmentStorage->resolveStoredPath($attachment->getFichierChemin());
        if ($path === null) {
            throw $this->createNotFoundException('Le fichier justificatif est indisponible.');
        }

        $this->logAction($alert, AccessLog::ACTION_LECTURE, 'Téléchargement du justificatif ' . $attachment->getFichierNomOriginal(), $em);
        $em->flush();

        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', $attachment->getMimeType() ?? 'application/octet-stream');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $attachment->getFichierNomOriginal(),
        );

        return $response;
    }

    #[Route('/{token}/edit', name: 'app_alert_edit', methods: ['GET', 'POST'], requirements: ['token' => '[A-Za-z0-9_-]+'])]
    public function edit(string $token, Request $request, AlertRepository $alertRepository): Response
    {
        $alert = $this->resolveAlertToken($token, $alertRepository);
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
            'typeLocalisation' => $alert->getTypeLocalisation()?->value,
            'localite' => $alert->getLocalite(),
            'typeLocalite' => $alert->getTypeLocalite(),
            'localiteLatitude' => $alert->getLocaliteLatitude(),
            'localiteLongitude' => $alert->getLocaliteLongitude(),
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

    #[Route('/{token}/delete', name: 'app_alert_delete', methods: ['POST'], requirements: ['token' => '[A-Za-z0-9_-]+'])]
    public function delete(
        string $token,
        Request $request,
        AlertRepository $alertRepository,
        EntityManagerInterface $em,
        StatistiquesService $statistiquesService,
        \App\Service\AlertCodeGeneratorService $codeGenerator,
        AlertAttachmentStorageService $attachmentStorage,
    ): Response {
        $alert = $this->resolveAlertToken($token, $alertRepository);
        $this->denyAccessUnlessGranted(AlertVoter::DECIDE, $alert);

        if (!$this->isCsrfTokenValid('delete', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Jeton CSRF invalide.');
            return $this->redirectToRoute('app_alert_show', ['token' => $token]);
        }

        $codeGei = $alert->getCodeGei() ?? '#' . $alert->getId();
        $user    = $this->getUser();
        $motif   = $request->request->get('motif_suppression', 'Aucun motif précisé');

        // Détacher les logs d'accès existants pour préserver l'audit sans bloquer la FK
        $em->createQuery('UPDATE App\Entity\AccessLog l SET l.alert = NULL WHERE l.alert = :alert')
           ->setParameter('alert', $alert)
           ->execute();

        // Supprimer les notifications liées
        $em->createQuery('DELETE FROM App\Entity\Notification n WHERE n.alert = :alert')
           ->setParameter('alert', $alert)
           ->execute();

        // Détacher les incidents de sécurité éventuels
        $em->createQuery('UPDATE App\Entity\IncidentSecurity i SET i.alert = NULL WHERE i.alert = :alert')
           ->setParameter('alert', $alert)
           ->execute();

        // Log d'audit de la suppression
        $log = new AccessLog();
        $log->setUser($user instanceof \App\Entity\User ? $user : null);
        $log->setAction(AccessLog::ACTION_SUPPRESSION);
        $log->setIpAdresse($request->getClientIp());
        $log->setUserAgent($request->headers->get('User-Agent'));
        $log->setResultat('success');
        $log->setDetails(sprintf(
            'Suppression complète de %s par %s — Motif : %s',
            $codeGei,
            $user instanceof \App\Entity\User ? $user->getNomComplet() : 'inconnu',
            $motif
        ));
        $em->persist($log);

        $attachmentPaths = [];
        foreach ($alert->getPieceJointes() as $attachment) {
            $attachmentPaths[] = $attachment->getFichierChemin();
        }

        // Suppression en cascade via Doctrine (suivis, historiques, transmissions, commentaires, urgence 72h)
        $em->remove($alert);
        $em->flush();
        foreach ($attachmentPaths as $attachmentPath) {
            $attachmentStorage->removeStored($attachmentPath);
        }

        // Réinitialiser le cache des numéros de séquence pour permettre la réattribution immédiate
        $codeGenerator->resetSequenceCache();
        $statistiquesService->invalidateAll();

        $this->addFlash('success', sprintf('Alerte %s et l\'ensemble de son suivi ont été supprimés avec succès.', $codeGei));

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
