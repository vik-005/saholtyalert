<?php

namespace App\Controller\Alert;

use App\Entity\AccessLog;
use App\Entity\Alert;
use App\Entity\AlertTransmission;
use App\Enum\AlertStatut;
use App\Form\TransmissionType;
use App\Voter\AlertVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/alert')]
#[IsGranted('ROLE_USER')]
class TransmissionController extends AbstractController
{
    #[Route('/{id}/transmit', name: 'app_alert_transmit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function transmit(
        Request $request,
        Alert $alert,
        EntityManagerInterface $em,
    ): Response {
        $this->denyAccessUnlessGranted(AlertVoter::TRANSMIT, $alert);

        if ($alert->getStatut() !== AlertStatut::VALIDEE) {
            throw $this->createAccessDeniedException('Une alerte doit être validée par le Manager avant toute transmission.');
        }

        $transmission = new AlertTransmission();
        $transmission->setAlert($alert);

        $user = $this->getUser();
        if ($user instanceof \App\Entity\User) {
            $transmission->setTransmisBy($user);
        }

        $form = $this->createForm(TransmissionType::class, $transmission);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $transmission->setStatut(AlertTransmission::STATUT_EN_COURS);
            $alert->setTransmission(\App\Enum\TransmissionStatut::OUI);
            $em->persist($transmission);

            $log = new AccessLog();
            $log->setAction(AccessLog::ACTION_TRANSMISSION);
            $log->setAlert($alert);
            $log->setUser($this->getUser());
            $log->setDetails('Transmis à : ' . $transmission->getDestinataire());
            $em->persist($log);

            $em->flush();

            $this->addFlash('success', 'Transmission enregistrée avec succès.');

            return $this->redirectToRoute('app_alert_show', ['id' => $alert->getId()]);
        }

        return $this->render('alert/transmission.html.twig', [
            'alert' => $alert,
            'form' => $form,
        ]);
    }

    /**
     * Écran de suivi des transmissions (réservé au Manager - Spec E.2).
     */
    #[Route('/transmissions/suivi', name: 'app_transmission_index', methods: ['GET'])]
    #[IsGranted('ROLE_PFT')]
    public function index(Request $request, EntityManagerInterface $em): Response
    {
        $statutFilter = $request->query->get('statut', '');

        $qb = $em->getRepository(AlertTransmission::class)->createQueryBuilder('t')
            ->join('t.alert', 'a')
            ->leftJoin('t.transmisBy', 'u')
            ->addSelect('a', 'u')
            ->orderBy('t.transmisLe', 'DESC');

        if ($statutFilter === 'en_cours') {
            $qb->andWhere('t.statut = :statut')->setParameter('statut', AlertTransmission::STATUT_EN_COURS);
        } elseif ($statutFilter === 'cloture') {
            $qb->andWhere('t.statut = :statut')->setParameter('statut', AlertTransmission::STATUT_CLOTURE);
        }

        $transmissions = $qb->getQuery()->getResult();

        $nbEnCours = (int) $em->getRepository(AlertTransmission::class)->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->where('t.statut = :statut')
            ->setParameter('statut', AlertTransmission::STATUT_EN_COURS)
            ->getQuery()->getSingleScalarResult();

        $nbClotures = (int) $em->getRepository(AlertTransmission::class)->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->where('t.statut = :statut')
            ->setParameter('statut', AlertTransmission::STATUT_CLOTURE)
            ->getQuery()->getSingleScalarResult();

        return $this->render('alert/transmission_list.html.twig', [
            'transmissions' => $transmissions,
            'current_filter' => $statutFilter,
            'nb_en_cours' => $nbEnCours,
            'nb_clotures' => $nbClotures,
            'total' => $nbEnCours + $nbClotures,
        ]);
    }

    /**
     * Bascule manuelle du statut vers "Clôturé" par le Manager (Spec E.2).
     */
    #[Route('/transmissions/{id}/cloturer', name: 'app_transmission_cloturer', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_PFT')]
    public function cloturer(Request $request, AlertTransmission $transmission, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('cloturer-transmission-' . $transmission->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Jeton de sécurité invalide.');
            return $this->redirectToRoute('app_transmission_index');
        }

        $transmission->cloturer();

        $log = new AccessLog();
        $log->setAction(AccessLog::ACTION_MODIFICATION);
        $log->setAlert($transmission->getAlert());
        $log->setUser($this->getUser());
        $log->setDetails(sprintf('Clôture du suivi de transmission vers %s', $transmission->getDestinataire()));
        $em->persist($log);

        $em->flush();

        $this->addFlash('success', sprintf('Le suivi de la transmission vers "%s" a été marqué comme clôturé.', $transmission->getDestinataire()));

        return $this->redirectToRoute('app_transmission_index');
    }

    /**
     * Réouvre une transmission précédemment clôturée (Spec D.3).
     * Repasse le statut au statut "en cours de résolution".
     */
    #[Route('/transmissions/{id}/rouvrir', name: 'app_transmission_rouvrir', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_PFT')]
    public function rouvrir(Request $request, AlertTransmission $transmission, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('rouvrir-transmission-' . $transmission->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Jeton de sécurité invalide.');
            return $this->redirectToRoute('app_transmission_index');
        }

        $transmission->rouvrir();

        $log = new AccessLog();
        $log->setAction(AccessLog::ACTION_MODIFICATION);
        $log->setAlert($transmission->getAlert());
        $log->setUser($this->getUser());
        $log->setDetails(sprintf('Réouverture du suivi de transmission vers %s', $transmission->getDestinataire()));
        $em->persist($log);

        $em->flush();

        $this->addFlash('success', sprintf('Le suivi de la transmission vers "%s" a été réouvert.', $transmission->getDestinataire()));

        return $this->redirectToRoute('app_transmission_index');
    }
}
