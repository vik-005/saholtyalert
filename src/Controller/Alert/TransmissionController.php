<?php

namespace App\Controller\Alert;

use App\Entity\Alert;
use App\Entity\AlertTransmission;
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

        $transmission = new AlertTransmission();
        $transmission->setAlert($alert);

        $user = $this->getUser();
        if ($user instanceof \App\Entity\User) {
            $transmission->setTransmisBy($user);
        }

        $form = $this->createForm(TransmissionType::class, $transmission);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $alert->setTransmission(\App\Enum\TransmissionStatut::OUI);
            $em->persist($transmission);
            $em->flush();

            $this->addFlash('success', 'Transmission enregistrée avec succès.');

            return $this->redirectToRoute('app_alert_show', ['id' => $alert->getId()]);
        }

        return $this->render('alert/transmission.html.twig', [
            'alert' => $alert,
            'form' => $form,
        ]);
    }
}

