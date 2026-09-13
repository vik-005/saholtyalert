<?php

namespace App\Controller\Admin;

use App\Entity\AccessLog;
use App\Repository\AccessLogRepository;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/activites')]
#[IsGranted('ROLE_PFT')]
class ActivityController extends AbstractController
{
    #[Route('/', name: 'app_admin_activity_index', methods: ['GET'])]
    public function index(
        Request $request,
        AccessLogRepository $logRepo,
        UserRepository $userRepo,
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof \App\Entity\User) {
            throw $this->createAccessDeniedException();
        }

        $filters = [
            'userId'    => $request->query->get('userId'),
            'action'    => $request->query->get('action'),
            'alertCode' => $request->query->get('alertCode'),
            'dateDebut' => $request->query->get('dateDebut'),
            'dateFin'   => $request->query->get('dateFin'),
        ];

        $page    = max(1, (int) $request->query->get('page', 1));
        $perPage = 50;

        $result = $logRepo->findFiltered($filters, $page, $perPage);

        // Liste des utilisateurs pour le filtre (périmètre du Manager)
        $users = $userRepo->findAll();

        return $this->render('admin/activites.html.twig', [
            'logs'       => $result['logs'],
            'total'      => $result['total'],
            'page'       => $result['page'],
            'totalPages' => $result['totalPages'],
            'filters'    => $filters,
            'users'      => $users,
            'actions'    => [
                AccessLog::ACTION_LECTURE       => 'Consultation',
                AccessLog::ACTION_CREATION      => 'Création',
                AccessLog::ACTION_MODIFICATION  => 'Modification',
                AccessLog::ACTION_SUPPRESSION   => 'Suppression',
                AccessLog::ACTION_EXPORT        => 'Export',
                AccessLog::ACTION_TRANSMISSION  => 'Transmission',
                AccessLog::ACTION_LOGIN         => 'Connexion',
                AccessLog::ACTION_LOGOUT        => 'Déconnexion',
                AccessLog::ACTION_VUE_SOURCE    => 'Vue source',
            ],
        ]);
    }

    #[Route('/export', name: 'app_admin_activity_export', methods: ['GET'])]
    public function export(
        Request $request,
        AccessLogRepository $logRepo,
    ): StreamedResponse {
        $this->denyAccessUnlessGranted('ROLE_PFT');

        $filters = [
            'userId'    => $request->query->get('userId'),
            'action'    => $request->query->get('action'),
            'alertCode' => $request->query->get('alertCode'),
            'dateDebut' => $request->query->get('dateDebut'),
            'dateFin'   => $request->query->get('dateFin'),
        ];

        $result = $logRepo->findFiltered($filters, 1, 10000);
        $logs   = $result['logs'];

        $response = new StreamedResponse(function () use ($logs) {
            $handle = fopen('php://output', 'w');

            // BOM UTF-8 pour Excel
            fwrite($handle, "\xEF\xBB\xBF");

            // En-têtes CSV
            fputcsv($handle, [
                'Date/Heure',
                'Utilisateur',
                'Action',
                'Alerte (Code GEI)',
                'Détails',
                'IP',
                'Résultat',
            ], ';');

            foreach ($logs as $log) {
                fputcsv($handle, [
                    $log->getDateAction()->format('d/m/Y H:i:s'),
                    $log->getUser()?->getNomComplet() ?? 'Système',
                    $log->getAction(),
                    $log->getAlert()?->getCodeGei() ?? '—',
                    $log->getDetails() ?? '',
                    $log->getIpAdresse() ?? '—',
                    $log->getResultat(),
                ], ';');
            }

            fclose($handle);
        });

        $filename = 'journal_activite_' . date('Y-m-d') . '.csv';
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');

        return $response;
    }
}
