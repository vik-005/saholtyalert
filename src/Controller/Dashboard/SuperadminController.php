<?php

namespace App\Controller\Dashboard;

use App\Repository\AccessLogRepository;
use App\Repository\UserRepository;
use App\Enum\UserRoleEnum;
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

    #[Route('/admin/performance', name: 'app_dashboard_superadmin_performance', methods: ['GET'])]
    public function performance(UserRepository $userRepository): Response
    {
        $profiles = $userRepository->getOperationalPerformanceStats();
        $roleLabels = [];
        foreach (UserRoleEnum::cases() as $role) {
            $roleLabels[$role->value] = $role->label();
        }

        $summary = [
            'users' => count($profiles),
            'submitted' => 0,
            'validated' => 0,
            'rejected' => 0,
            'pending' => 0,
            'transmitted' => 0,
            'managerReviewed' => 0,
        ];
        $agencyStats = [];
        $roleStats = [];
        foreach ($profiles as $profile) {
            foreach (['submitted', 'validated', 'rejected', 'pending', 'transmitted'] as $metric) {
                $summary[$metric] += $profile[$metric];
            }
            $summary['managerReviewed'] += $profile['reviewed'];

            $agency = $profile['agency'] ?: 'Profil régional';
            $agencyStats[$agency] ??= [
                'name' => $agency,
                'users' => 0,
                'submitted' => 0,
                'validated' => 0,
                'rejected' => 0,
                'reviewed' => 0,
            ];
            ++$agencyStats[$agency]['users'];
            foreach (['submitted', 'validated', 'rejected', 'reviewed'] as $metric) {
                $agencyStats[$agency][$metric] += $profile[$metric];
            }

            $role = $profile['role'];
            $roleStats[$role] ??= [
                'label' => $roleLabels[$role] ?? $role,
                'users' => 0,
                'submitted' => 0,
                'validated' => 0,
                'rejected' => 0,
                'reviewed' => 0,
            ];
            ++$roleStats[$role]['users'];
            foreach (['submitted', 'validated', 'rejected', 'reviewed'] as $metric) {
                $roleStats[$role][$metric] += $profile[$metric];
            }
        }
        usort($agencyStats, static fn (array $left, array $right): int => $right['submitted'] <=> $left['submitted']);

        return $this->render('dashboard/performance.html.twig', [
            'profiles' => $profiles,
            'summary' => $summary,
            'agencies' => $agencyStats,
            'roleStats' => $roleStats,
            'roleLabels' => $roleLabels,
        ]);
    }
}
