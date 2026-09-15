<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Enum\UserRoleEnum;
use App\Form\UserType;
use App\Repository\UserRepository;
use App\Voter\UserVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/user')]
#[IsGranted('ROLE_PFT')]
class UserController extends AbstractController
{
    #[Route('/', name: 'app_admin_user_index', methods: ['GET'])]
    public function index(UserRepository $userRepository): Response
    {
        $currentUser = $this->getUser();
        if (!$currentUser instanceof User) {
            throw $this->createAccessDeniedException();
        }

        if ($currentUser->getRole() === UserRoleEnum::SUPERADMIN) {
            $users = $userRepository->findAll();
        } else {
            // Pour un Manager (PFT), afficher les agents de ses marchés gérés + son compte
            $managedMarkets = $currentUser->getAllManagedMarkets();
            $agents = $userRepository->findAgentsByMarkets($managedMarkets);
            $users = array_unique(array_merge([$currentUser], $agents), SORT_REGULAR);
        }

        return $this->render('admin/users.html.twig', [
            'users' => $users,
            'isSuperadmin' => $currentUser->getRole() === UserRoleEnum::SUPERADMIN,
        ]);
    }

    #[Route('/new', name: 'app_admin_user_new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher,
    ): Response {
        $currentUser = $this->getUser();
        if (!$currentUser instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $this->denyAccessUnlessGranted(UserVoter::CREATE);

        $user = new User();
        // Si c'est un manager, pré-assigner le rôle agent
        if ($currentUser->getRole() === UserRoleEnum::PFT) {
            $user->setRole(UserRoleEnum::EMETTEUR_TERRAIN);
        }

        $form = $this->createForm(UserType::class, $user, [
            'is_new' => true,
            'current_user' => $currentUser,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // ── Garde serveur : création SUPERADMIN interdite via UI (défense en profondeur) ──
            if ($user->getRole() === UserRoleEnum::SUPERADMIN
                && $currentUser->getRole() !== UserRoleEnum::SUPERADMIN) {
                throw $this->createAccessDeniedException('Vous ne pouvez pas créer un Super Administrateur.');
            }
            // Un SUPERADMIN ne peut pas en créer un autre via l'interface
            if ($user->getRole() === UserRoleEnum::SUPERADMIN
                && $currentUser->getRole() === UserRoleEnum::SUPERADMIN) {
                $this->addFlash('error', 'La création d\'un Super Administrateur via l\'interface est désactivée. Utilisez la console Symfony.');
                return $this->render('admin/user_form.html.twig', [
                    'form'   => $form,
                    'user'   => $user,
                    'is_new' => true,
                ]);
            }

            // Sécurité stricte : si manager, forcer rôle Agent et marchés gérés
            if ($currentUser->getRole() === UserRoleEnum::PFT) {
                $user->setRole(UserRoleEnum::EMETTEUR_TERRAIN);

                // Enregistrer le Manager créateur (Partie C — jamais d'auto-inscription)
                $user->setCreatedByManager($currentUser);

                $managedMarkets = $currentUser->getAllManagedMarkets();

                // Valider les marchés agent assignés — ne conserver que ceux du périmètre Manager
                foreach ($user->getAgentMarkets() as $agentMarket) {
                    if (!in_array($agentMarket, $managedMarkets, true)) {
                        $user->removeAgentMarket($agentMarket);
                    }
                }

                // Valider le marché principal (legacy) si renseigné
                if ($user->getMarket() !== null && !in_array($user->getMarket(), $managedMarkets, true)) {
                    $this->addFlash('error', 'Le marché principal sélectionné n\'est pas sous votre responsabilité.');
                    return $this->render('admin/user_form.html.twig', [
                        'form' => $form,
                        'user' => $user,
                        'is_new' => true,
                    ]);
                }

                // Si aucun marché agent assigné, utiliser le marché principal comme fallback
                if ($user->getAgentMarkets()->isEmpty() && $user->getMarket() !== null) {
                    $user->addAgentMarket($user->getMarket());
                }

                // Au moins un marché obligatoire
                if ($user->getAgentMarkets()->isEmpty() && $user->getMarket() === null) {
                    $this->addFlash('error', 'L\'Agent doit être rattaché à au moins un marché de votre périmètre.');
                    return $this->render('admin/user_form.html.twig', [
                        'form' => $form,
                        'user' => $user,
                        'is_new' => true,
                    ]);
                }
            }

            $plainPassword = $form->get('plainPassword')->getData();
            if ($plainPassword) {
                $user->setPassword($hasher->hashPassword($user, $plainPassword));
            }

            $em->persist($user);
            $em->flush();

            $this->addFlash('success', sprintf('Utilisateur %s créé avec succès.', $user->getNomComplet()));

            return $this->redirectToRoute('app_admin_user_index');
        }

        return $this->render('admin/user_form.html.twig', [
            'form' => $form,
            'user' => $user,
            'is_new' => true,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_admin_user_edit', methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        User $user,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher,
    ): Response {
        $currentUser = $this->getUser();
        if (!$currentUser instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $this->denyAccessUnlessGranted(UserVoter::EDIT, $user);

        $form = $this->createForm(UserType::class, $user, [
            'is_new' => false,
            'current_user' => $currentUser,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Sécurité : si manager, ne pas autoriser le changement de rôle ni de périmètre marché
            if ($currentUser->getRole() === UserRoleEnum::PFT) {
                $user->setRole(UserRoleEnum::EMETTEUR_TERRAIN);

                $managedMarkets = $currentUser->getAllManagedMarkets();

                // Nettoyer les marchés agent hors périmètre
                foreach ($user->getAgentMarkets() as $agentMarket) {
                    if (!in_array($agentMarket, $managedMarkets, true)) {
                        $user->removeAgentMarket($agentMarket);
                    }
                }

                if ($user->getMarket() !== null && !in_array($user->getMarket(), $managedMarkets, true)) {
                    $user->setMarket(null);
                }
            }

            $plainPassword = $form->get('plainPassword')->getData();
            if ($plainPassword) {
                $user->setPassword($hasher->hashPassword($user, $plainPassword));
            }

            $em->flush();

            $this->addFlash('success', sprintf('Utilisateur %s mis à jour.', $user->getNomComplet()));

            return $this->redirectToRoute('app_admin_user_index');
        }

        return $this->render('admin/user_form.html.twig', [
            'form' => $form,
            'user' => $user,
            'is_new' => false,
        ]);
    }

    #[Route('/{id}/delete', name: 'app_admin_user_delete', methods: ['POST'])]
    public function delete(Request $request, User $user, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted(UserVoter::DELETE, $user);

        $token = $request->request->get('_token');

        if (!$this->isCsrfTokenValid('delete-user-' . $user->getId(), $token)) {
            $this->addFlash('error', 'Jeton de sécurité invalide.');
            return $this->redirectToRoute('app_admin_user_index');
        }

        $em->remove($user);
        $em->flush();

        $this->addFlash('success', sprintf('Utilisateur %s supprimé avec succès.', $user->getNomComplet()));

        return $this->redirectToRoute('app_admin_user_index');
    }
}
