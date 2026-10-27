<?php

namespace App\Controller\Admin;

use App\Entity\ListeReferenceValeur;
use App\Entity\RuleConfig;
use App\Repository\AccessLogRepository;
use App\Service\AdminAuditLogger;
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
    public function index(EntityManagerInterface $em, AccessLogRepository $logRepository, AdminAuditLogger $auditLogger): Response
    {
        $auditLogger->log(\App\Entity\AccessLog::ACTION_LECTURE, 'automations');
        $em->flush();
        $rules = $em->getRepository(RuleConfig::class)->findAll();
        
        // Institutions de transition / transmission configurées manuellement
        $institutions = $em->getRepository(ListeReferenceValeur::class)->findBy(
            ['typeListe' => 'institution_transmission'],
            ['ordreAffichage' => 'ASC', 'libelle' => 'ASC']
        );

        // Logs d'escalades et automatisations récentes
        $triggers = $logRepository->createQueryBuilder('l')
            ->where('l.action LIKE :act')
            ->setParameter('act', '%escalade%')
            ->orderBy('l.dateAction', 'DESC')
            ->setMaxResults(30)
            ->getQuery()
            ->getResult();

        return $this->render('admin/automations.html.twig', [
            'rules'              => $rules,
            'institutions'       => $institutions,
            'triggers'           => $triggers,
            'last_scheduler_run' => new \DateTime(),
        ]);
    }

    #[Route('/institution/new', name: 'app_admin_automation_institution_new', methods: ['POST'])]
    public function addInstitution(Request $request, EntityManagerInterface $em, AdminAuditLogger $auditLogger): Response
    {
        if (!$this->isCsrfTokenValid('add_institution_auto', (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Jeton CSRF invalide.');
            return $this->redirectToRoute('app_admin_automations');
        }

        $libelle = trim((string) $request->request->get('libelle', ''));
        $ordre = (int) $request->request->get('ordre_affichage', 0);

        if ($libelle === '') {
            $this->addFlash('danger', 'Le nom de l\'institution de transition est obligatoire.');
            return $this->redirectToRoute('app_admin_automations');
        }

        $existing = $em->getRepository(ListeReferenceValeur::class)->findOneBy([
            'typeListe' => 'institution_transmission',
            'libelle'   => $libelle,
        ]);
        if ($existing) {
            $this->addFlash('danger', sprintf('L\'institution « %s » existe déjà.', $libelle));
            return $this->redirectToRoute('app_admin_automations');
        }

        $item = new ListeReferenceValeur();
        $item->setTypeListe('institution_transmission');
        $item->setLibelle($libelle);
        $item->setOrdreAffichage($ordre);
        $item->setActif(true);
        $user = $this->getUser();
        if ($user instanceof \App\Entity\User) {
            $item->setCreePar($user);
        }
        $em->persist($item);
        $em->flush();

        $auditLogger->log(\App\Entity\AccessLog::ACTION_CREATION, 'institution_transmission', $item->getId());
        $em->flush();

        $this->addFlash('success', sprintf('Institution de transition « %s » ajoutée avec succès.', $libelle));
        return $this->redirectToRoute('app_admin_automations');
    }

    #[Route('/institution/{id}/toggle', name: 'app_admin_automation_institution_toggle', methods: ['POST'])]
    public function toggleInstitution(Request $request, ListeReferenceValeur $institution, EntityManagerInterface $em, AdminAuditLogger $auditLogger): Response
    {
        if (!$this->isCsrfTokenValid('toggle_institution_' . $institution->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Jeton CSRF invalide.');
            return $this->redirectToRoute('app_admin_automations');
        }

        $institution->setActif(!$institution->isActif());
        $auditLogger->log(\App\Entity\AccessLog::ACTION_MODIFICATION, 'institution_status', $institution->getId());
        $em->flush();

        $this->addFlash('info', sprintf('Institution « %s » %s.', $institution->getLibelle(), $institution->isActif() ? 'activée' : 'désactivée'));
        return $this->redirectToRoute('app_admin_automations');
    }

    #[Route('/rule/{id}/toggle', name: 'app_admin_automation_rule_toggle', methods: ['POST'])]
    public function toggleRule(Request $request, RuleConfig $rule, EntityManagerInterface $em, AdminAuditLogger $auditLogger): Response
    {
        if (!$this->isCsrfTokenValid('toggle-rule-' . $rule->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Jeton de sécurité invalide.');
            return $this->redirectToRoute('app_admin_automations');
        }

        $rule->setActif(!$rule->isActif());
        $auditLogger->log(\App\Entity\AccessLog::ACTION_MODIFICATION, 'automation_status', $rule->getId());
        $em->flush();

        $this->addFlash('success', sprintf('Automatisation "%s" %s avec succès.', $rule->getCle(), $rule->isActif() ? 'activée' : 'désactivée'));

        return $this->redirectToRoute('app_admin_automations');
    }

    #[Route('/rule/{id}/update', name: 'app_admin_automation_rule_update', methods: ['POST'])]
    public function updateRule(Request $request, RuleConfig $rule, EntityManagerInterface $em, AdminAuditLogger $auditLogger): Response
    {
        if (!$this->isCsrfTokenValid('update-rule-' . $rule->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Jeton de sécurité invalide.');
            return $this->redirectToRoute('app_admin_automations');
        }

        $valeur = $request->request->get('valeur');
        if ($valeur !== null) {
            $rule->setValeur(trim($valeur));
            $auditLogger->log(\App\Entity\AccessLog::ACTION_MODIFICATION, 'automation_rule', $rule->getId());
            $em->flush();

            $this->addFlash('success', sprintf('Seuil de la règle "%s" mis à jour : %s', $rule->getCle(), $valeur));
        }

        return $this->redirectToRoute('app_admin_automations');
    }
}
