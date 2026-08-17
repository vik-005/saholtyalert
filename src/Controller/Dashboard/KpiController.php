<?php

namespace App\Controller\Dashboard;

use App\Repository\AlertRepository;
use App\Repository\MarketRepository;
use App\Repository\Urgence72hCaseRepository;
use App\Service\KPIService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_COMITE_AIT')]
class KpiController extends AbstractController
{
    #[Route('/kpi', name: 'app_dashboard_kpi')]
    public function index(
        AlertRepository $alertRepository,
        Urgence72hCaseRepository $urgenceRepo,
        MarketRepository $marketRepository,
        KPIService $kpiService,
    ): Response {
        // Période par défaut : dernière semaine
        $fin = new \DateTime();
        $debut = (clone $fin)->sub(new \DateInterval('P7D'));

        // Récupérer tous les marchés (ou limiter selon le rôle de l'utilisateur)
        $marches = $marketRepository->findAll();
        $marchesIds = array_map(fn($m) => $m->getId(), $marches);

        // KPI cartes principales
        $kpiAlertesSoumises = $kpiService->getAlertesSoumises($debut, $fin, $marchesIds);
        $kpiAlerteesTraitees = $kpiService->getAlerteesTraitees($debut, $fin, $marchesIds);
        $kpiTauxTransmission = $kpiService->getTauxTransmission($debut, $fin, $marchesIds);
        $kpiScoreMoyen = $kpiService->getScoreMoyen($debut, $fin, $marchesIds);

        // Données graphiques
        $courbeTemporelle = $kpiService->getCourbeSoumisesVsQualifiees($debut, $fin, $marchesIds);
        $repartitionPriorite = $kpiService->getRepartitionPriorite($marchesIds);

        // Cas urgence 72h
        $casUrgence72h = $kpiService->getCasUrgence72hActifs($marchesIds);

        // Alertes récentes
        $alertesRecentes = $kpiService->getAlerteesRecentes(8, $marchesIds);

        // Activité récente
        $activiteRecente = $kpiService->getActiviteRecente(10);

        // Statistiques globales (compatibilité ancienne interface)
        $kpis = $alertRepository->getKpiStats();
        
        $delaiMoyenTotal = 0;
        $countClosed = 0;
        foreach ($urgenceRepo->findAll() as $case) {
            if ($case->getDelaiReelHeures()) {
                $delaiMoyenTotal += $case->getDelaiReelHeures();
                $countClosed++;
            }
        }
        $delaiMoyen = $countClosed > 0 ? round($delaiMoyenTotal / $countClosed, 1) : 48;

        return $this->render('dashboard/kpi.html.twig', [
            // KPI cartes principales
            'kpi_alertes_soumises' => $kpiAlertesSoumises,
            'kpi_alertes_traitees' => $kpiAlerteesTraitees,
            'kpi_taux_transmission' => $kpiTauxTransmission,
            'kpi_score_moyen' => $kpiScoreMoyen,
            
            // Graphiques
            'courbe_temporelle' => $courbeTemporelle,
            'repartition_priorite' => $repartitionPriorite,
            
            // Urgence 72h
            'cas_urgence_72h' => $casUrgence72h,
            
            // Alertes récentes
            'alertes_recentes' => $alertesRecentes,
            
            // Activité
            'activite_recente' => $activiteRecente,
            
            // Legacy data
            'kpis' => $kpis,
            'delai_moyen_72h' => $delaiMoyen,
            'cases_count' => count($casUrgence72h),
            
            // Paramètres de période
            'debut' => $debut,
            'fin' => $fin,
        ]);
    }
}

