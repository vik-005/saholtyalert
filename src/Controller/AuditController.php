<?php

namespace App\Controller;

use App\Dto\AuditFilterDTO;
use App\Enum\AlertStatut;
use App\Enum\AlertUrgence;
use App\Enum\NiveauPriorite;
use App\Enum\TypeAlerte;
use App\Enum\UserRoleEnum;
use App\Repository\AlertAuditRepository;
use App\Repository\ListeReferenceValeurRepository;
use App\Repository\MarketRepository;
use App\Repository\UserRepository;
use App\Service\AuditService;
use App\Service\AuditExcelExportService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Page Audit — Recherche multicritère avancée.
 *
 * Délègue toute la logique à AuditService + AlertAuditRepository (existants).
 * Résultats au choix : tableau texte ou 4 graphiques Chart.js.
 */
#[Route('/audit')]
#[IsGranted('ROLE_PFT')]
class AuditController extends AbstractController
{
    #[Route('/', name: 'app_audit_index', methods: ['GET', 'POST'])]
    public function index(
        Request                       $request,
        AuditService                  $auditService,
        MarketRepository              $marketRepo,
        UserRepository                $userRepo,
        ListeReferenceValeurRepository $listeRefRepo,
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof \App\Entity\User) {
            throw $this->createAccessDeniedException();
        }

        $dto        = AuditFilterDTO::fromRequest($request);
        $vue        = $request->query->get('vue', $request->request->get('vue', 'texte'));
        $dto->vue   = $vue;
        $hasSearch  = $this->hasActiveFilters($dto);

        $alerts  = [];
        $stats   = [];
        $total   = 0;

        if ($hasSearch) {
            // Export CSV avant rendu HTML
            if ($request->query->get('_export') === 'csv') {
                return $this->exportCsv($dto, $auditService, $user);
            }

            $result = $auditService->getResults($dto, $user);
            $alerts = $result['rows'];
            $total  = $result['total'];
            $stats  = $auditService->getChartData($dto, $user);
        }

        return $this->render('audit/index.html.twig', [
            'alerts'      => $alerts,
            'results'     => $alerts,
            'stats'       => $stats,
             'filters'     => [
                'markets'         => $dto->markets,
                'categories'      => $dto->categories,
                'typeSources'     => $dto->typeSources,
                'corridors'       => $dto->corridors,
                'statuts'         => $dto->statuts,
                'urgences'        => $dto->urgences,
                'niveauxPriorite' => $dto->niveauxPriorite,
                'pays'            => $dto->markets,
                'anneeDebut'      => $dto->dateFrom?->format('Y'),
                'anneeFin'        => $dto->dateTo?->format('Y'),
                'dateFrom'        => $dto->dateFrom?->format('Y-m-d'),
                'dateTo'          => $dto->dateTo?->format('Y-m-d'),
                'operateur'       => $dto->operateur,
                'marque'          => $dto->marque,
                'categorie'       => $dto->categories[0] ?? null,
                'typeAlerte'      => $dto->typeAlerte,
                'statut'          => $dto->statuts[0] ?? null,
                'priorite'        => $dto->niveauxPriorite[0] ?? null,
                'texteLibre'      => $dto->texteLibre,
                'origine'         => $dto->origine,
                'agentId'         => $dto->agentId,
                'managerId'       => $dto->managerId,
                'scoreMin'        => $dto->scoreMin,
                'scoreMax'        => $dto->scoreMax,
            ],
            'vue'         => $vue,
            'total'       => $total,
            'hasSearch'   => $hasSearch,
            'categories'  => $listeRefRepo->findActivesByType('categorie'),
            'typeSources' => $listeRefRepo->findActivesByType('type_source'),
            'statuts'     => AlertStatut::cases(),
            'niveaux'     => NiveauPriorite::cases(),
            'typeAlertes' => TypeAlerte::cases(),
            'urgences'    => AlertUrgence::cases(),
            'agents'      => $userRepo->findByRole(UserRoleEnum::EMETTEUR_TERRAIN),
            'managers'    => $userRepo->findByRole(UserRoleEnum::SUPERADMIN),
            'isSuperadmin' => $this->isGranted('ROLE_SUPERADMIN'),
            'markets'     => $user->getRole() === UserRoleEnum::PFT
                ? $user->getAllManagedMarkets()
                : $marketRepo->findActifs(),
        ]);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function hasActiveFilters(AuditFilterDTO $dto): bool
    {
        return !empty($dto->markets)
            || $dto->dateFrom !== null
            || $dto->dateTo !== null
            || $dto->operateur !== ''
            || $dto->marque !== ''
            || !empty($dto->categories)
            || !empty($dto->typeSources)
            || !empty($dto->statuts)
            || !empty($dto->niveauxPriorite)
            || $dto->texteLibre !== ''
            || $dto->typeAlerte !== null;
    }

    private function exportCsv(AuditFilterDTO $dto, AuditService $auditService, \App\Entity\User $user): StreamedResponse
    {
        $result = $auditService->getResults($dto, $user);
        $alerts = $result['rows'];

        $response = new StreamedResponse(function () use ($alerts, $dto) {
            $h = fopen('php://output', 'w');
            fwrite($h, "\xEF\xBB\xBF"); // BOM UTF-8

            fputcsv($h, ['# Audit GEI — ' . date('d/m/Y H:i')], ';');
            $parts = [];
            if ($dto->operateur) { $parts[] = 'Opérateur: ' . $dto->operateur; }
            if ($dto->marque)    { $parts[] = 'Marque: '    . $dto->marque; }
            if ($parts) {
                fputcsv($h, ['# Filtres: ' . implode(' | ', $parts)], ';');
            }
            fputcsv($h, ['# ' . count($alerts) . ' résultats'], ';');
            fputcsv($h, [], ';');

            fputcsv($h, [
                'Code GEI', 'Pays', 'Corridor', 'Date',
                'Catégorie', 'Type alerte', 'Priorité', 'Score', 'Statut',
                'Résumé exécutif', 'Acteurs',
            ], ';');

            foreach ($alerts as $alert) {
                $acteurs = $alert->getActeurs()->map(fn($a) => $a->getNomOuRaisonSociale())->toArray();
                fputcsv($h, [
                    $alert->getCodeGei() ?? '',
                    $alert->getMarket()?->getNom() ?? '',
                    $alert->getPortCorridor() ?? '',
                    $alert->getDateCreation()->format('d/m/Y'),
                    $alert->getCategorie() ?? '',
                    $alert->getTypeAlerte()?->label() ?? '',
                    $alert->getEffectiveNiveauPriorite()?->label() ?? '',
                    $alert->getEffectiveScore() ?? '',
                    $alert->getStatut()?->label() ?? '',
                    $alert->getResumeExecutif() ?? '',
                    implode(', ', $acteurs),
                ], ';');
            }
            fclose($h);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition',
            'attachment; filename="audit_gei_' . date('Y-m-d_Hi') . '.csv"');
        return $response;
    }

    /**
     * Export Excel (multi-feuilles : Résumé, Données, Stats par marché/categorie/statut/ mois).
     */
    #[Route('/export/excel', name: 'app_audit_export_excel', methods: ['POST'])]
    public function exportExcel(
        Request $request,
        AuditService $auditService,
        AuditExcelExportService $excelExport,
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof \App\Entity\User) {
            throw $this->createAccessDeniedException();
        }

        $dto = AuditFilterDTO::fromRequest($request);
        $result = $auditService->getResults($dto, $user);
        $stats = $auditService->getChartData($dto, $user);

        return $excelExport->export($dto, $stats, $result['rows']);
    }

    /**
     * Export PDF (A4 landscape, en-tête et footer officiels).
     */
    #[Route('/export/pdf', name: 'app_audit_export_pdf', methods: ['POST'])]
    public function exportPdf(
        Request $request,
        AuditService $auditService,
        AuditPdfExportService $pdfExport,
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof \App\Entity\User) {
            throw $this->createAccessDeniedException();
        }

        $dto = AuditFilterDTO::fromRequest($request);
        $result = $auditService->getResults($dto, $user);
        $stats = $auditService->getChartData($dto, $user);

        $pdfContent = $pdfExport->generatePdf($dto, $user, $stats, $result['rows']);

        $response = new Response($pdfContent);
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set('Content-Disposition', sprintf(
            'attachment; filename="Audit_GEI_%s.pdf"',
            (new \DateTime())->format('Y-m-d_His')
        ));
        $response->headers->set('Cache-Control', 'max-age=0');
        return $response;
    }

    /**
     * Autocomplétion des valeurs d'Opérateur / Acteur connues en base.
     * Retourne un tableau JSON de chaînes correspondant aux valeurs déjà saisies.
     */
    #[Route('/operateurs/autocomplete', name: 'app_audit_operateurs_autocomplete', methods: ['GET'])]
    public function operateursAutocomplete(
        Request $request,
        AlertAuditRepository $auditRepo,
    ): JsonResponse {
        $search = trim((string) $request->query->get('q', ''));
        $operateurs = $auditRepo->findDistinctOperateurs($search !== '' ? $search : null);
        return new JsonResponse($operateurs);
    }
}
