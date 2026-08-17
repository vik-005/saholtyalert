<?php

namespace App\Controller\Dashboard;

use App\Repository\AccessLogRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_SUPERADMIN')]
class SuperadminController extends AbstractController
{
    #[Route('/admin', name: 'app_dashboard_superadmin')]
    public function index(
        UserRepository $userRepository,
        AccessLogRepository $logRepository,
        EntityManagerInterface $em,
    ): Response {
        $users = $userRepository->findAll();
        $logs = $logRepository->findLatestLogs(50);
        $incidents = $em->getRepository(\App\Entity\IncidentSecurity::class)->findBy([], ['signaleLe' => 'DESC'], 20);
        $rules = $em->getRepository(\App\Entity\RuleConfig::class)->findAll();

        return $this->render('dashboard/superadmin.html.twig', [
            'users' => $users,
            'logs' => $logs,
            'incidents' => $incidents,
            'rules' => $rules,
        ]);
    }
}
