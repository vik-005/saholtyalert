<?php

namespace App\Controller\Alert;

use App\Entity\ActivityLog;
use App\Entity\ImportBatch;
use App\Entity\User;
use App\Form\ImportType;
use App\Message\ImportExcelMessage;
use App\Repository\ActivityLogRepository;
use App\Repository\ImportBatchRepository;
use App\Service\ExportService;
use App\Service\ImportService;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Contracts\Cache\CacheInterface;

#[IsGranted('ROLE_PFT')]
class ImportController extends AbstractController
{
    private const ASYNC_THRESHOLD = 500;

    #[Route('/alert/import', name: 'app_alert_import', methods: ['GET', 'POST'], priority: 10)]
    public function import(Request $request, ImportService $importService, ImportBatchRepository $batchRepository, ActivityLogRepository $activityRepository, MessageBusInterface $bus, ManagerRegistry $registry, CacheInterface $cache): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $form = $this->createForm(ImportType::class);
        $result = null;
        $dryRun = null;
        $session = $request->getSession();

        if ($request->isMethod('POST') && $request->request->get('confirm') === '1') {
            if (!$this->isCsrfTokenValid('import_excel_confirm', (string) $request->request->get('_confirm_token'))) {
                throw $this->createAccessDeniedException('Jeton CSRF invalide.');
            }
            $preview = $session->get('alert_import_preview');
            if (is_array($preview) && is_file($preview['path'] ?? '')) {
                $path = $preview['path'];
                $batchId = $preview['batch_id'];
                $total = (int) $preview['total'];
                $session->remove('alert_import_preview');
                if ($total > self::ASYNC_THRESHOLD) {
                    $batch = (new ImportBatch())->setBatchId($batchId)->setUser($user)->setFilename($preview['filename'])->setLinesTotal($total);
                    $batchRepository->save($batch);
                    $bus->dispatch(new ImportExcelMessage($path, (int) $user->getId(), $batchId));
                    $result = ['queued' => true, 'batch_id' => $batchId, 'total' => $total];
                    $this->addFlash('success', sprintf('Import lancé en arrière-plan pour %d lignes.', $total));
                } else {
                    $result = $importService->importPath($path, $user, $batchId);
                    $activityUser = $registry->getManager()->getRepository(User::class)->find($user->getId());
                    if (!$activityUser instanceof User) {
                        throw new \RuntimeException('Utilisateur de journalisation introuvable après l’import.');
                    }
                    $activityRepository->save(ActivityLog::log($activityUser, 'import_excel', $batchId, sprintf('Import terminé : %d créées, %d mises à jour, %d erreurs.', $result['success_count'], $result['update_count'], $result['error_count']), $result));
                    $this->addFlash('success', sprintf('%d alertes créées, %d mises à jour.', $result['success_count'], $result['update_count']));
                    @unlink($path);
                    if (method_exists($cache, 'invalidateTags')) {
                        $cache->invalidateTags(['kpi', 'alertes']);
                    }
                }
            } else {
                $this->addFlash('danger', 'La prévisualisation a expiré. Veuillez sélectionner le fichier à nouveau.');
            }
        } else {
            $form->handleRequest($request);
            if ($form->isSubmitted() && $form->isValid()) {
                $file = $form->get('file')->getData();
                if ($file) {
                    $dryRun = $importService->dryRun($file, $user);
                    if (empty($dryRun['header_errors'])) {
                        $path = tempnam(sys_get_temp_dir(), 'gei_import_');
                        copy($file->getPathname(), $path);
                        $session->set('alert_import_preview', ['path' => $path, 'batch_id' => uniqid('import_', true), 'total' => $dryRun['total'], 'filename' => $file->getClientOriginalName()]);
                        $this->addFlash('info', 'Analyse terminée. Confirmez l’import après vérification du résumé.');
                    }
                }
            }
        }

        return $this->render('alert/import.html.twig', ['form' => $form, 'result' => $result, 'dryRun' => $dryRun]);
    }

    #[Route('/alert/import/template', name: 'app_alert_import_template', methods: ['GET'])]
    public function template(): Response
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->fromArray([ExportService::COLUMNS]);
        $spreadsheet->getActiveSheet()->getStyle('A1:AD1')->getFont()->setBold(true);
        $stream = fopen('php://memory', 'w+b');
        (new Xlsx($spreadsheet))->save($stream);
        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);
        $spreadsheet->disconnectWorksheets();
        return new Response($content, Response::HTTP_OK, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Content-Disposition' => 'attachment; filename="gabarit_registre_gei.xlsx"']);
    }
}
