<?php

namespace App\Controller\Admin;

use App\Entity\RuleConfig;
use App\Form\RuleConfigType;
use App\Repository\RuleConfigRepository;
use App\Service\AdminAuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/rule')]
#[IsGranted('ROLE_SUPERADMIN')]
class RuleConfigController extends AbstractController
{
    /**
     * Page liste de toutes les règles de scoring — accessible depuis le menu Admin.
     */
    #[Route('/', name: 'app_admin_rule_index', methods: ['GET'])]
    public function index(RuleConfigRepository $repo, AdminAuditLogger $auditLogger): Response
    {
        $auditLogger->logAndFlush(\App\Entity\AccessLog::ACTION_LECTURE, 'rule_config');
        $rules = $repo->findBy([], ['categorie' => 'ASC', 'cle' => 'ASC']);

        return $this->render('admin/rule_config_index.html.twig', [
            'rules' => $rules,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_admin_rule_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, RuleConfig $rule, EntityManagerInterface $em, AdminAuditLogger $auditLogger): Response
    {
        $form = $this->createForm(RuleConfigType::class, $rule);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $auditLogger->log(\App\Entity\AccessLog::ACTION_MODIFICATION, 'rule_config', $rule->getId());
            $em->flush();
            $this->addFlash('success', sprintf('Règle "%s" mise à jour avec succès.', $rule->getCle()));
            return $this->redirectToRoute('app_admin_rule_index');
        }

        return $this->render('admin/rule_config.html.twig', [
            'form' => $form,
            'rule' => $rule,
        ]);
    }
}
