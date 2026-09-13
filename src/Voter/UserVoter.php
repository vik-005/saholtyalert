<?php

namespace App\Voter;

use App\Entity\Market;
use App\Entity\User;
use App\Enum\UserRoleEnum;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

class UserVoter extends Voter
{
    public const VIEW = 'USER_VIEW';
    public const CREATE = 'USER_CREATE';
    public const EDIT = 'USER_EDIT';
    public const DELETE = 'USER_DELETE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        if (!in_array($attribute, [self::VIEW, self::CREATE, self::EDIT, self::DELETE], true)) {
            return false;
        }

        // Pour CREATE, le subject peut être null ou une instance de User
        if ($attribute === self::CREATE) {
            return $subject === null || $subject instanceof User;
        }

        return $subject instanceof User;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $currentUser = $token->getUser();
        if (!$currentUser instanceof User) {
            return false;
        }

        // Le SUPERADMIN a un contrôle total
        if ($currentUser->getRole() === UserRoleEnum::SUPERADMIN) {
            return true;
        }

        // Seul le Manager (PFT) peut avoir des droits délégués sur les agents de ses marchés
        if ($currentUser->getRole() !== UserRoleEnum::PFT) {
            return false;
        }

        /** @var User|null $targetUser */
        $targetUser = $subject;

        return match ($attribute) {
            self::VIEW => $this->canView($currentUser, $targetUser),
            self::CREATE => true, // Le manager peut créer un agent (vérification fine du rôle/marché dans le form/controller)
            self::EDIT => $this->canEdit($currentUser, $targetUser),
            self::DELETE => $this->canDelete($currentUser, $targetUser),
            default => false,
        };
    }

    private function canView(User $currentUser, ?User $targetUser): bool
    {
        if ($targetUser === null || $targetUser->getId() === $currentUser->getId()) {
            return true;
        }

        // Le manager ne peut voir que les Agents rattachés à l'un de ses marchés gérés
        if ($targetUser->getRole() !== UserRoleEnum::EMETTEUR_TERRAIN) {
            return false;
        }

        return $this->isUserInManagerMarkets($currentUser, $targetUser);
    }

    private function canEdit(User $currentUser, ?User $targetUser): bool
    {
        if ($targetUser === null) {
            return false;
        }

        // Un utilisateur peut modifier son propre compte (nom/prénom etc.)
        if ($targetUser->getId() === $currentUser->getId()) {
            return true;
        }

        // Le manager ne peut modifier que des Agents rattachés à l'un de ses marchés gérés
        if ($targetUser->getRole() !== UserRoleEnum::EMETTEUR_TERRAIN) {
            return false;
        }

        return $this->isUserInManagerMarkets($currentUser, $targetUser);
    }

    private function canDelete(User $currentUser, ?User $targetUser): bool
    {
        if ($targetUser === null) {
            return false;
        }

        // On ne peut pas supprimer son propre compte via ce biais
        if ($targetUser->getId() === $currentUser->getId()) {
            return false;
        }

        // Le manager ne peut supprimer que des Agents rattachés à ses marchés
        if ($targetUser->getRole() !== UserRoleEnum::EMETTEUR_TERRAIN) {
            return false;
        }

        return $this->isUserInManagerMarkets($currentUser, $targetUser);
    }

    private function isUserInManagerMarkets(User $manager, User $agent): bool
    {
        $agentMarket = $agent->getMarket();
        if ($agentMarket === null) {
            return false;
        }

        $managerMarkets = $manager->getAllManagedMarkets();
        return in_array($agentMarket, $managerMarkets, true);
    }
}
