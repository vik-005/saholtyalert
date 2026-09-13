<?php

namespace App\Controller\Admin;

use App\Entity\Market;
use App\Entity\ZoneGeo;
use App\Repository\MarketRepository;
use App\Repository\ZoneGeoRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Gestion des pays/marchés — accessible à partir du rôle Manager (PFT).
 * Permet d'ajouter, modifier, activer/désactiver un pays.
 * Les pays actifs apparaissent dans tous les selects du système.
 */
#[Route('/admin/marches')]
#[IsGranted('ROLE_PFT')]
class MarketController extends AbstractController
{
    #[Route('/', name: 'app_admin_market_index', methods: ['GET'])]
    public function index(MarketRepository $repo): Response
    {
        $markets = $repo->findBy([], ['nom' => 'ASC']);

        return $this->render('admin/market/index.html.twig', [
            'markets' => $markets,
        ]);
    }

    #[Route('/new', name: 'app_admin_market_new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        EntityManagerInterface $em,
        ZoneGeoRepository $zoneGeoRepo,
        ValidatorInterface $validator,
    ): Response {
        if ($request->isMethod('POST')) {
            $nom      = trim($request->request->get('nom', ''));
            $iso3     = strtoupper(trim($request->request->get('codeIso3', '')));
            $region   = trim($request->request->get('region', 'Afrique de l\'Ouest'));
            $lat      = $request->request->get('latitude');
            $lng      = $request->request->get('longitude');

            $errors = [];
            if (empty($nom))  { $errors[] = 'Le nom du pays est obligatoire.'; }
            if (strlen($iso3) !== 3) { $errors[] = 'Le code ISO3 doit faire exactement 3 lettres.'; }

            // Vérifier unicité ISO3
            $existing = $em->getRepository(Market::class)->findOneBy(['codeIso3' => $iso3]);
            if ($existing) { $errors[] = "Le code ISO3 « {$iso3} » est déjà utilisé par « {$existing->getNom()} »."; }

            if (empty($errors)) {
                $market = new Market();
                $market->setNom($nom);
                $market->setCodeIso3($iso3);
                $market->setRegion($region);
                $market->setActif(true);
                $em->persist($market);
                $em->flush();

                // Créer automatiquement la zone_geo si coordonnées fournies
                if ($lat && $lng && is_numeric($lat) && is_numeric($lng)) {
                    $zone = new ZoneGeo();
                    $zone->setMarket($market);
                    $zone->setLatitude((float) $lat);
                    $zone->setLongitude((float) $lng);
                    $zone->setNom($nom);
                    $em->persist($zone);
                    $em->flush();
                }

                $this->addFlash('success', "Pays « {$nom} » ajouté avec succès. Il apparaît maintenant dans tous les selects.");
                return $this->redirectToRoute('app_admin_market_index');
            }

            foreach ($errors as $err) {
                $this->addFlash('danger', $err);
            }
        }

        // Régions disponibles
        $regions = [
            'Afrique de l\'Ouest',
            'Afrique Centrale',
            'Afrique de l\'Est',
            'Afrique du Nord',
            'Afrique Australe',
        ];

        return $this->render('admin/market/new.html.twig', [
            'regions' => $regions,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_admin_market_edit', methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        Market $market,
        EntityManagerInterface $em,
        ZoneGeoRepository $zoneGeoRepo,
    ): Response {
        $zone = $zoneGeoRepo->findOneBy(['market' => $market]);

        if ($request->isMethod('POST')) {
            $nom    = trim($request->request->get('nom', ''));
            $region = trim($request->request->get('region', $market->getRegion()));
            $lat    = $request->request->get('latitude');
            $lng    = $request->request->get('longitude');

            if (!empty($nom)) {
                $market->setNom($nom);
            }
            $market->setRegion($region);

            // Mettre à jour ou créer la zone_geo
            if ($lat && $lng && is_numeric($lat) && is_numeric($lng)) {
                if ($zone === null) {
                    $zone = new ZoneGeo();
                    $zone->setMarket($market);
                    $em->persist($zone);
                }
                $zone->setLatitude((float) $lat);
                $zone->setLongitude((float) $lng);
                $zone->setNom($nom ?: $market->getNom());
            }

            $em->flush();

            $this->addFlash('success', "Pays « {$market->getNom()} » mis à jour.");
            return $this->redirectToRoute('app_admin_market_index');
        }

        $regions = [
            'Afrique de l\'Ouest',
            'Afrique Centrale',
            'Afrique de l\'Est',
            'Afrique du Nord',
            'Afrique Australe',
        ];

        return $this->render('admin/market/edit.html.twig', [
            'market'  => $market,
            'zone'    => $zone,
            'regions' => $regions,
        ]);
    }

    #[Route('/{id}/toggle', name: 'app_admin_market_toggle', methods: ['POST'])]
    public function toggle(Request $request, Market $market, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('toggle-market-' . $market->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Jeton CSRF invalide.');
            return $this->redirectToRoute('app_admin_market_index');
        }

        $market->setActif(!$market->isActif());
        $em->flush();

        $etat = $market->isActif() ? 'activé' : 'désactivé';
        $this->addFlash('info', "Pays « {$market->getNom()} » {$etat}.");

        return $this->redirectToRoute('app_admin_market_index');
    }

    #[Route('/{id}/delete', name: 'app_admin_market_delete', methods: ['POST'])]
    public function delete(Request $request, Market $market, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('delete-market-' . $market->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Jeton CSRF invalide.');
            return $this->redirectToRoute('app_admin_market_index');
        }

        // Vérifier qu'aucune alerte n'est associée à ce pays
        $alertCount = $em->createQueryBuilder()
            ->select('COUNT(a.id)')
            ->from('App\Entity\Alert', 'a')
            ->where('a.market = :market')
            ->setParameter('market', $market)
            ->getQuery()
            ->getSingleScalarResult();

        if ($alertCount > 0) {
            $this->addFlash('danger', "Impossible de supprimer « {$market->getNom()} » : {$alertCount} alerte(s) y sont associées. Désactivez-le plutôt.");
            return $this->redirectToRoute('app_admin_market_index');
        }

        $nom = $market->getNom();
        $em->remove($market);
        $em->flush();

        $this->addFlash('success', "Pays « {$nom} » supprimé définitivement.");
        return $this->redirectToRoute('app_admin_market_index');
    }
}
