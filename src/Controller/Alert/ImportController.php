<?php

namespace App\Controller\Alert;

use App\Form\ImportType;
use App\Service\ImportService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Cache\CacheInterface;

#[IsGranted('ROLE_USER')]
class ImportController extends AbstractController
{
    /**
     * Route /alert/import déclarée avec priority: 10 pour être résolue
     * AVANT la route /alert/{id} (qui n'accepte que des entiers).
     */
    #[Route('/alert/import', name: 'app_alert_import', methods: ['GET', 'POST'], priority: 10)]
    public function import(
        Request        $request,
        ImportService  $importService,
        CacheInterface $cache,
    ): Response {
        $form = $this->createForm(ImportType::class);
        $form->handleRequest($request);

        $result = null;
        $dryRun = null;

        if ($form->isSubmitted() && $form->isValid()) {
            $file = $form->get('file')->getData();
            $user = $this->getUser();

            if ($file && $user instanceof \App\Entity\User) {
                try {
                    // Analyse à blanc avant écriture : elle permet de préparer le diagnostic
                    // des lignes incomplètes, puis l'import est lancé par le bouton visible.
                    $dryRun = $importService->dryRun($file, $user);

                    $result = $importService->importExcel($file, $user);

                    // Invalider le cache KPI immédiatement après import
                    try {
                        $cache->delete('kpi_alertes_soumises_' . md5(''));
                        if (method_exists($cache, 'invalidateTags')) {
                            $cache->invalidateTags(['kpi', 'alertes']);
                        }
                        if (method_exists($cache, 'clear')) {
                            $cache->clear();
                        }
                    } catch (\Exception) {
                    }

                    if ($result['success_count'] > 0 || $result['update_count'] > 0) {
                        $this->addFlash('success', sprintf(
                            '%d alertes créées, %d mises à jour.',
                            $result['success_count'],
                            $result['update_count']
                        ));
                    }

                    if (!empty($result['lignes_incompletes'])) {
                        $this->addFlash('warning', sprintf(
                            '%d ligne(s) avec champs manquants — à qualifier via le bouton "Qualifier".',
                            count($result['lignes_incompletes'])
                        ));
                    }

                    if ($result['error_count'] > 0) {
                        $this->addFlash('danger', sprintf('%d ligne(s) en erreur.', $result['error_count']));
                    }
                } catch (\Exception $e) {
                    $result = [
                        'success_count'      => 0,
                        'update_count'       => 0,
                        'error_count'        => 1,
                        'errors'             => ['Erreur critique : ' . $e->getMessage() . ' (ligne ' . $e->getLine() . ' de ' . basename($e->getFile()) . ')'],
                        'lignes_incompletes' => [],
                        'batch_id'           => 'error',
                    ];
                    $this->addFlash('danger', 'Erreur lors de l\'import : ' . $e->getMessage());
                }
            }
        } elseif ($form->isSubmitted()) {
            foreach ($form->getErrors(true) as $error) {
                $this->addFlash('danger', $error->getMessage());
            }
        }

        return $this->render('alert/import.html.twig', [
            'form'   => $form,
            'result' => $result,
            'dryRun' => $dryRun,
        ]);
    }
}