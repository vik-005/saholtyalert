<?php

namespace App\Controller;

use App\Enum\UserRoleEnum;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class LegalController extends AbstractController
{
    #[Route('/confidentialite', name: 'app_privacy_policy', methods: ['GET'])]
    public function privacyPolicy(): Response
    {
        return $this->render('legal/document.html.twig', [
            'document' => 'privacy',
            'roles' => [],
        ]);
    }

    #[Route('/conditions-utilisation', name: 'app_terms_of_use', methods: ['GET'])]
    public function termsOfUse(): Response
    {
        return $this->render('legal/document.html.twig', [
            'document' => 'terms',
            'roles' => UserRoleEnum::cases(),
        ]);
    }
}
