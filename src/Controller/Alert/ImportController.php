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

        if ($form->isSubmitted() && $form->isValid()) {
            $file = $form->get('file')->getData();
            $user = $this->getUser();

            if ($file && $user instanceof \App\Entity\User) {
                try {
                    $result = $importService->importExcel($file, $user);
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

                // Invalider le cache KPI immédiatement après import
                // (évite le délai de 5 min du TTL du KPIService)
                try {
                    $cache->delete('kpi_alertes_soumises_' . md5(''));
                    // Invalidation par tags si le pool le supporte
                    if (method_exists($cache, 'invalidateTags')) {
                        $cache->invalidateTags(['kpi', 'alertes']);
                    }
                } catch (\Exception) {
                    // Invalidation du cache non critique — continuer sans erreur
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
            }
        }

        return $this->render('alert/import.html.twig', [
            'form'   => $form,
            'result' => $result,
        ]);
    }
}
