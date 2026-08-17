<?php

namespace App\Controller\Admin;

use App\Entity\RuleConfig;
use App\Repository\AccessLogRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/automations')]
#[IsGranted('ROLE_PFT')]
class AutomationsController extends AbstractController
{
    #[Route('/', name: 'app_admin_automations', methods: ['GET'])]
    public function index(EntityManagerInterface $em, AccessLogRepository $logRepository): Response
    {
        $rules = $em->getRepository(RuleConfig::class)->findAll();
        
        // Logs d'escalades et automatisations récentes
        $triggers = $logRepository->createQueryBuilder('l')
            ->where('l.action LIKE :act')
            ->setParameter('act', '%escalade%')
            ->orderBy('l.dateAction', 'DESC')
            ->setMaxResults(30)
            ->getQuery()
            ->getResult();

        return $this->render('admin/automations.html.twig', [
            'rules' => $rules,
            'triggers' => $triggers,
            'last_scheduler_run' => new \DateTime(),
        ]);
    }

    #[Route('/rule/{id}/toggle', name: 'app_admin_automation_rule_toggle', methods: ['POST'])]
    public function toggleRule(RuleConfig $rule, EntityManagerInterface $em): Response
    {
        $rule->setActif(!$rule->isActif());
        $em->flush();

        $this->addFlash('success', sprintf('Automatisation "%s" %s avec succès.', $rule->getCle(), $rule->isActif() ? 'activée' : 'désactivée'));

        return $this->redirectToRoute('app_admin_automations');
    }

    #[Route('/rule/{id}/update', name: 'app_admin_automation_rule_update', methods: ['POST'])]
    public function updateRule(Request $request, RuleConfig $rule, EntityManagerInterface $em): Response
    {
        $valeur = $request->request->get('valeur');
        if ($valeur !== null) {
            $rule->setValeur(trim($valeur));
            $em->flush();

            $this->addFlash('success', sprintf('Seuil de la règle "%s" mis à jour : %s', $rule->getCle(), $valeur));
        }

        return $this->redirectToRoute('app_admin_automations');
    }
}
