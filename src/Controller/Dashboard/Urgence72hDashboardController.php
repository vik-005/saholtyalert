<?php

namespace App\Controller\Dashboard;

use App\Repository\Urgence72hCaseRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_PFT')]
class Urgence72hDashboardController extends AbstractController
{
    #[Route('/urgence', name: 'app_dashboard_urgence')]
    public function index(Urgence72hCaseRepository $urgenceRepo): Response
    {
        $activeCases = $urgenceRepo->findActiveCases();
        $closedCases = $urgenceRepo->findBy(['statutCase' => 'cloturee'], ['dateActivation' => 'DESC'], 20);

        return $this->render('dashboard/urgence.html.twig', [
            'active_cases' => $activeCases,
            'closed_cases' => $closedCases,
        ]);
    }
}
