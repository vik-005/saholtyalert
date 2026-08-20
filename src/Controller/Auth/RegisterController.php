<?php

namespace App\Controller\Auth;

use App\Entity\User;
use App\Enum\UserRoleEnum;
use App\Repository\MarketRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Inscription d'un nouvel utilisateur.
 *
 * Le compte est créé avec le rôle EMETTEUR_TERRAIN par défaut
 * et reste inactif jusqu'à validation par un SUPERADMIN.
 * Note : dans un contexte opérationnel réel, l'inscription est
 * désactivée et les comptes créés manuellement par l'admin.
 */
class RegisterController extends AbstractController
{
    #[Route('/register', name: 'app_register', methods: ['GET', 'POST'])]
    public function register(
        Request                     $request,
        UserPasswordHasherInterface $hasher,
        EntityManagerInterface      $em,
        MarketRepository            $marketRepository,
    ): Response {
        // Déjà connecté → dashboard
        if ($this->getUser()) {
            return $this->redirectToRoute('app_dashboard_operationnel');
        }

        $errors = [];
        $formData = [];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('register', (string) $request->request->get('_token'))) {
                $errors[] = 'Votre session a expiré. Veuillez réessayer.';
            }
            $formData = [
                'prenom'   => trim($request->request->get('prenom', '')),
                'nom'      => trim($request->request->get('nom', '')),
                'email'    => trim($request->request->get('email', '')),
                'fonction' => trim($request->request->get('fonction', '')),
                'pays'     => trim($request->request->get('pays', '')),
            ];
            $password        = $request->request->get('password', '');
            $passwordConfirm = $request->request->get('password_confirm', '');
            $terms           = $request->request->get('terms');

            // ── Validation ──────────────────────────────────────────────────
            if (empty($formData['prenom'])) {
                $errors[] = 'Le prénom est obligatoire.';
            }
            if (empty($formData['nom'])) {
                $errors[] = 'Le nom est obligatoire.';
            }
            if (empty($formData['email']) || !filter_var($formData['email'], FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Adresse email invalide.';
            }
            if (strlen($password) < 12 || !preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/\d/', $password)) {
                $errors[] = 'Le mot de passe doit contenir 12 caractères, une majuscule, une minuscule et un chiffre.';
            }
            if ($password !== $passwordConfirm) {
                $errors[] = 'Les mots de passe ne correspondent pas.';
            }
            if (!$terms) {
                $errors[] = 'Vous devez accepter les conditions d\'utilisation.';
            }

            // Vérifier doublon email
            if (empty($errors)) {
                $existing = $em->getRepository(User::class)->findOneBy(['email' => $formData['email']]);
                if ($existing) {
                    $errors[] = 'Cette adresse email est déjà utilisée.';
                }
            }

            // ── Création ────────────────────────────────────────────────────
            if (empty($errors)) {
                $user = new User();
                $user->setPrenom($formData['prenom']);
                $user->setNom($formData['nom']);
                $user->setEmail($formData['email']);
                $user->setFonction($formData['fonction'] ?: null);
                $user->setRole(UserRoleEnum::EMETTEUR_TERRAIN);
                $user->setActif(false); // activation manuelle par admin
                $user->setPassword($hasher->hashPassword($user, $password));

                // Associer au marché si renseigné
                if (!empty($formData['pays'])) {
                    $market = $marketRepository->findOneByNomOrIso($formData['pays']);
                    if ($market) {
                        $user->setMarket($market);
                    }
                }

                $em->persist($user);
                $em->flush();

                $this->addFlash('success',
                    'Compte créé avec succès. Un administrateur validera votre accès sous 24h.'
                );

                return $this->redirectToRoute('app_login');
            }
        }

        return $this->render('auth/register.html.twig', [
            'form'     => (object)['vars' => ['errors' => $errors], 'prenom' => (object)['vars' => ['value' => $formData['prenom'] ?? '']], 'nom' => (object)['vars' => ['value' => $formData['nom'] ?? '']], 'email' => (object)['vars' => ['value' => $formData['email'] ?? '']], 'pays' => (object)['vars' => ['value' => $formData['pays'] ?? '']]],
            'errors'   => $errors,
            'formData' => $formData,
        ]);
    }
}
