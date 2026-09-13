<?php

namespace App\Controller\Admin;

use App\Entity\ListeReferenceValeur;
use App\Repository\ListeReferenceValeurRepository;
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
    public function index(ListeReferenceValeurRepository $repo): Response
    {
        $categories = $repo->findAllByType('categorie');
        $typeSources = $repo->findAllByType('type_source');

        return $this->render('admin/liste_reference/index.html.twig', [
            'categories'  => $categories,
            'typeSources' => $typeSources,
        ]);
    }

    #[Route('/new', name: 'app_admin_liste_reference_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $user = $this->getUser();

        if ($request->isMethod('POST')) {
            $typeListe = $request->request->get('type_liste', '');
            $libelle  = trim($request->request->get('libelle', ''));
            $ordre    = (int) $request->request->get('ordre_affichage', 0);

            $errors = [];
            if (!in_array($typeListe, ['categorie', 'type_source'], true)) {
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

            if (empty($errors)) {
                $item = new ListeReferenceValeur();
                $item->setTypeListe($typeListe);
                $item->setLibelle($libelle);
                $item->setOrdreAffichage($ordre);
                $item->setActif(true);
                $item->setCreePar($user);
                $em->persist($item);
                $em->flush();

                $this->addFlash('success', sprintf('« %s » ajouté à la liste %s.', $libelle, $typeListe));
                return $this->redirectToRoute('app_admin_liste_reference_index');
            }

            foreach ($errors as $err) {
                $this->addFlash('danger', $err);
            }
        }

        return $this->render('admin/liste_reference/new.html.twig');
    }

    #[Route('/{id}/toggle', name: 'app_admin_liste_reference_toggle', methods: ['POST'])]
    public function toggle(Request $request, ListeReferenceValeur $item, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('toggle-lrv-' . $item->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Jeton CSRF invalide.');
            return $this->redirectToRoute('app_admin_liste_reference_index');
        }

        $item->setActif(!$item->isActif());
        $em->flush();

        $etat = $item->isActif() ? 'activé' : 'désactivé';
        $this->addFlash('info', sprintf('« %s » %s.', $item->getLibelle(), $etat));

        return $this->redirectToRoute('app_admin_liste_reference_index');
    }

    #[Route('/{id}/edit', name: 'app_admin_liste_reference_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, ListeReferenceValeur $item, EntityManagerInterface $em): Response
    {
        if ($request->isMethod('POST')) {
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
