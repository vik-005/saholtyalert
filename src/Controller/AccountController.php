<?php

namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/compte')]
#[IsGranted('ROLE_USER')]
final class AccountController extends AbstractController
{
    #[Route('', name: 'app_account_settings', methods: ['GET', 'POST'])]
    public function settings(Request $request, EntityManagerInterface $em, UserPasswordHasherInterface $hasher): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        if ($request->isMethod('POST') && $request->request->get('action') === 'profile') {
            if (!$this->isCsrfTokenValid('account-profile', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
            }
            $prenom = trim((string) $request->request->get('prenom'));
            $nom = trim((string) $request->request->get('nom'));
            if ($prenom === '' || $nom === '') {
                $this->addFlash('error', 'Le prénom et le nom sont obligatoires.');
                return $this->redirectToRoute('app_account_settings');
            }
            $user->setPrenom($prenom);
            $user->setNom($nom);
            $user->setTelephone(trim((string) $request->request->get('telephone')) ?: null);
            $user->setFonction(trim((string) $request->request->get('fonction')) ?: null);
            $em->flush();
            $this->addFlash('success', 'Vos informations de profil ont été mises à jour.');
            return $this->redirectToRoute('app_account_settings');
        }

        if ($request->isMethod('POST') && $request->request->get('action') === 'password') {
            if (!$this->isCsrfTokenValid('account-password', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
            }
            $current = (string) $request->request->get('current_password');
            $new = (string) $request->request->get('new_password');
            $confirmation = (string) $request->request->get('confirm_password');

            if (!$hasher->isPasswordValid($user, $current)) {
                $this->addFlash('error', 'Le mot de passe actuel est incorrect.');
            } elseif (strlen($new) < 12 || !preg_match('/[A-Z]/', $new) || !preg_match('/[a-z]/', $new) || !preg_match('/\d/', $new)) {
                $this->addFlash('error', 'Le nouveau mot de passe doit contenir 12 caractères, une majuscule, une minuscule et un chiffre.');
            } elseif (strtolower($new) === strtolower($user->getUserIdentifier())) {
                $this->addFlash('error', 'Le mot de passe ne peut pas être identique à votre adresse email.');
            } elseif (strtolower($new) === strtolower($user->getNomComplet())) {
                $this->addFlash('error', 'Le mot de passe ne peut pas être identique à votre nom.');
            } elseif ($new !== $confirmation) {
                $this->addFlash('error', 'La confirmation du nouveau mot de passe ne correspond pas.');
            } else {
                $user->setPassword($hasher->hashPassword($user, $new));
                $em->flush();

                // Invalider toutes les sessions actives après changement de mot de passe
                $session = $request->getSession();
                if ($session !== null) {
                    $session->invalidate();
                }

                $this->addFlash('success', 'Votre mot de passe a été modifié. Toutes les sessions ont été invalidées.');
            }
            return $this->redirectToRoute('app_account_settings');
        }

        return $this->render('account/settings.html.twig', ['user' => $user]);
    }
}
