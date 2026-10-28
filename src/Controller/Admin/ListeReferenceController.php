<?php

namespace App\Controller\Admin;

use App\Entity\ListeReferenceValeur;
use App\Repository\ListeReferenceValeurRepository;
use App\Service\AdminAuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Administration des listes de référence (Catégorie, Type de source, etc.).
 * Accessible au Manager (PFT) et au Superadmin.
 */
#[Route('/admin/listes-reference')]
#[IsGranted('ROLE_PFT')]
class ListeReferenceController extends AbstractController
{
    #[Route('/', name: 'app_admin_liste_reference_index', methods: ['GET'])]
    public function index(ListeReferenceValeurRepository $repo, EntityManagerInterface $em, AdminAuditLogger $auditLogger): Response
    {
        $auditLogger->logAndFlush(\App\Entity\AccessLog::ACTION_LECTURE, 'reference_lists');
        $categories   = $repo->findAllByType('categorie');
        $typeSources  = $repo->findAllByType('type_source');
        $localites    = $repo->findAllByType('localite');
        $institutions = $repo->findAllByType('institution_transmission');

        // Statistiques d'utilisation réelles par localité, type de source et catégorie
        $dbal = $em->getConnection();

        $localiteStats = [];
        try {
            $rowsLoc = $dbal->fetchAllAssociative(
                "SELECT LOWER(TRIM(val)) AS k, COUNT(id) AS cnt
                 FROM (
                     SELECT id, localite AS val FROM alert WHERE localite IS NOT NULL AND localite != ''
                     UNION ALL
                     SELECT id, port_corridor AS val FROM alert WHERE port_corridor IS NOT NULL AND port_corridor != ''
                 ) t GROUP BY LOWER(TRIM(val))"
            );
            foreach ($rowsLoc as $r) {
                $localiteStats[$r['k']] = (int) $r['cnt'];
            }
        } catch (\Throwable) {}

        $sourceStats = [];
        try {
            $rowsSrc = $dbal->fetchAllAssociative(
                "SELECT LOWER(TRIM(type_source)) AS k, COUNT(id) AS cnt FROM alert WHERE type_source IS NOT NULL AND type_source != '' GROUP BY LOWER(TRIM(type_source))"
            );
            foreach ($rowsSrc as $r) {
                $sourceStats[$r['k']] = (int) $r['cnt'];
            }
        } catch (\Throwable) {}

        $categorieStats = [];
        try {
            $rowsCat = $dbal->fetchAllAssociative(
                "SELECT LOWER(TRIM(categorie)) AS k, COUNT(id) AS cnt FROM alert WHERE categorie IS NOT NULL AND categorie != '' GROUP BY LOWER(TRIM(categorie))"
            );
            foreach ($rowsCat as $r) {
                $categorieStats[$r['k']] = (int) $r['cnt'];
            }
        } catch (\Throwable) {}

        return $this->render('admin/liste_reference/index.html.twig', [
            'categories'     => $categories,
            'typeSources'    => $typeSources,
            'localites'      => $localites,
            'institutions'   => $institutions,
            'localiteStats'  => $localiteStats,
            'sourceStats'    => $sourceStats,
            'categorieStats' => $categorieStats,
        ]);
    }

    #[Route('/new', name: 'app_admin_liste_reference_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em, AdminAuditLogger $auditLogger): Response
    {
        $user = $this->getUser();

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('lrv_new', (string) $request->request->get('_token'))) {
                $this->addFlash('danger', 'Jeton CSRF invalide.');
                return $this->redirectToRoute('app_admin_liste_reference_new');
            }

            $typeListe = (string) $request->request->get('type_liste', '');
            $libelle   = trim((string) $request->request->get('libelle', ''));
            $ordre     = (int) $request->request->get('ordre_affichage', 0);
            $nature    = trim((string) $request->request->get('nature_localite', ''));
            $actif     = (string) $request->request->get('actif', '1');

            // Si c'est une localité avec une nature précisée (ex: Port, Corridor), préfixer ou formater si non présent
            if ($typeListe === 'localite' && $nature !== '' && !str_starts_with($libelle, '[')) {
                $libelle = sprintf('[%s] %s', $nature, $libelle);
            }

            $errors = [];
            if (!in_array($typeListe, ['categorie', 'type_source', 'localite', 'institution_transmission'], true)) {
                $errors[] = 'Le type de liste est invalide.';
            }
            if ($libelle === '') {
                $errors[] = 'Le libellé est obligatoire.';
            } else {
                // Vérifier l'unicité du libellé pour ce type
                $existing = $em->getRepository(ListeReferenceValeur::class)->findOneBy([
                    'typeListe' => $typeListe,
                    'libelle'   => $libelle,
                ]);
                if ($existing) {
                    $errors[] = "Le libellé « {$libelle} » existe déjà dans cette liste.";
                }
            }
            if ($typeListe === 'institution_transmission' && !in_array($actif, ['0', '1'], true)) {
                $errors[] = 'Le statut de l\'institution est invalide.';
            }

            if (empty($errors)) {
                $item = new ListeReferenceValeur();
                $item->setTypeListe($typeListe);
                $item->setLibelle($libelle);
                $item->setOrdreAffichage($ordre);
                $item->setActif($typeListe !== 'institution_transmission' || $actif === '1');
                $item->setCreePar($user);
                $em->persist($item);
                $em->flush();
                $auditLogger->log(\App\Entity\AccessLog::ACTION_CREATION, 'reference_value', $item->getId());
                $em->flush();

                $this->addFlash('success', sprintf('« %s » ajouté à la liste %s.', $libelle, $typeListe));
                return $this->redirectToRoute('app_admin_liste_reference_index');
            }

            foreach ($errors as $err) {
                $this->addFlash('danger', $err);
            }
        }

        return $this->render('admin/liste_reference/new.html.twig', [
            'selectedTypeListe' => $request->isMethod('POST')
                ? (string) $request->request->get('type_liste', '')
                : (string) $request->query->get('type', ''),
            'selectedActif' => (string) $request->request->get('actif', '1'),
        ]);
    }

    #[Route('/{id}/toggle', name: 'app_admin_liste_reference_toggle', methods: ['POST'])]
    public function toggle(Request $request, ListeReferenceValeur $item, EntityManagerInterface $em, AdminAuditLogger $auditLogger): Response
    {
        if (!$this->isCsrfTokenValid('toggle-lrv-' . $item->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Jeton CSRF invalide.');
            return $this->redirectToRoute('app_admin_liste_reference_index');
        }

        $item->setActif(!$item->isActif());
        $auditLogger->log(\App\Entity\AccessLog::ACTION_MODIFICATION, 'reference_status', $item->getId());
        $em->flush();

        $etat = $item->isActif() ? 'activé' : 'désactivé';
        $this->addFlash('info', sprintf('« %s » %s.', $item->getLibelle(), $etat));

        return $this->redirectToRoute('app_admin_liste_reference_index');
    }

    #[Route('/{id}/delete', name: 'app_admin_liste_reference_delete', methods: ['POST'])]
    public function delete(Request $request, ListeReferenceValeur $item, EntityManagerInterface $em, AdminAuditLogger $auditLogger): Response
    {
        if (!$this->isCsrfTokenValid('delete-lrv-' . $item->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Jeton CSRF invalide.');
            return $this->redirectToRoute('app_admin_liste_reference_index');
        }

        // Sécurité : ne pas supprimer si la valeur est utilisée par des alertes existantes
        $libelle = $item->getLibelle();
        $auditLogger->log(\App\Entity\AccessLog::ACTION_SUPPRESSION, 'reference_value', $item->getId());
        $em->remove($item);
        $em->flush();

        $this->addFlash('success', sprintf('« %s » supprimé définitivement.', $libelle));
        return $this->redirectToRoute('app_admin_liste_reference_index');
    }

    #[Route('/{id}/edit', name: 'app_admin_liste_reference_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, ListeReferenceValeur $item, EntityManagerInterface $em, AdminAuditLogger $auditLogger): Response
    {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('edit-lrv-' . $item->getId(), (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Jeton CSRF invalide.');
            }

            $libelle = trim($request->request->get('libelle', ''));
            $ordre   = (int) $request->request->get('ordre_affichage', 0);

            if ($libelle === '') {
                $this->addFlash('danger', 'Le libellé est obligatoire.');
            } else {
                // Vérifier unicité (sauf pour lui-même)
                $existing = $em->getRepository(ListeReferenceValeur::class)->createQueryBuilder('l')
                    ->where('l.typeListe = :type')
                    ->andWhere('l.libelle = :libelle')
                    ->andWhere('l.id != :id')
                    ->setParameter('type', $item->getTypeListe())
                    ->setParameter('libelle', $libelle)
                    ->setParameter('id', $item->getId())
                    ->getQuery()
                    ->getOneOrNullResult();

                if ($existing) {
                    $this->addFlash('danger', "Le libellé « {$libelle} » existe déjà dans cette liste.");
                } else {
                    $item->setLibelle($libelle);
                    $item->setOrdreAffichage($ordre);
                    $auditLogger->log(\App\Entity\AccessLog::ACTION_MODIFICATION, 'reference_value', $item->getId());
                    $em->flush();
                    $this->addFlash('success', 'Modification enregistrée.');
                    return $this->redirectToRoute('app_admin_liste_reference_index');
                }
            }
        }

        return $this->render('admin/liste_reference/edit.html.twig', [
            'item' => $item,
        ]);
    }
}
