<?php

namespace App\Controller\Alert;

use App\Entity\Alert;
use App\Form\AlertType;
use App\Repository\AlertRepository;
use App\Service\AlertCodeGeneratorService;
use App\Voter\AlertVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/alert')]
#[IsGranted('ROLE_USER')]
class AlertController extends AbstractController
{
    #[Route('/', name: 'app_alert_index', methods: ['GET'])]
    public function index(Request $request, AlertRepository $alertRepository): Response
    {
        $user = $this->getUser();
        if (!$user instanceof \App\Entity\User) {
            throw $this->createAccessDeniedException();
        }

        // Récupération des paramètres de filtre depuis les query parameters
        $filters = [
            'statut' => $request->query->get('statut'),
            'niveauPriorite' => $request->query->get('priorite'),
            'typeAlerte' => $request->query->get('typeAlerte'),
            'market' => $request->query->get('market'),
            'search' => $request->query->get('search'),
            'categorie' => $request->query->get('categorie'),
            'urgence' => $request->query->get('urgence'),
        ];

        $alerts = $alertRepository->findForUser($user, $filters);

        return $this->render('alert/index.html.twig', [
            'alerts' => $alerts,
            'filters' => $filters,
        ]);
    }

    #[Route('/new', name: 'app_alert_new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        EntityManagerInterface $em,
        AlertCodeGeneratorService $codeGen,
    ): Response {
        $alert = new Alert();
        $user = $this->getUser();
        if ($user instanceof \App\Entity\User) {
            $alert->setEmetteur($user);
            if ($user->getMarket()) {
                $alert->setMarket($user->getMarket());
            }
        }

        $form = $this->createForm(AlertType::class, $alert);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if (null === $alert->getCodeGei() && null !== $alert->getMarket()) {
                $alert->setCodeGei($codeGen->generate($alert));
            }

            $em->persist($alert);
            $em->flush();

            $this->addFlash('success', sprintf('Alerte %s créée avec succès.', $alert->getCodeGei() ?? 'brouillon'));

            return $this->redirectToRoute('app_alert_show', ['id' => $alert->getId()]);
        }

        return $this->render('alert/new.html.twig', [
            'alert' => $alert,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_alert_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(Alert $alert): Response
    {
        $this->denyAccessUnlessGranted(AlertVoter::VIEW, $alert);

        return $this->render('alert/show.html.twig', [
            'alert' => $alert,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_alert_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, Alert $alert, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted(AlertVoter::EDIT_COLLECTE, $alert);

        $form = $this->createForm(AlertType::class, $alert);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();

            $this->addFlash('success', 'Alerte mise à jour avec succès.');

            return $this->redirectToRoute('app_alert_show', ['id' => $alert->getId()]);
        }

        return $this->render('alert/new.html.twig', [
            'alert' => $alert,
            'form' => $form,
            'is_edit' => true,
        ]);
    }
}
