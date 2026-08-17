<?php

namespace App\Voter;

use App\Entity\Alert;
use App\Entity\User;
use App\Enum\AlertStatut;
use App\Enum\UserRoleEnum;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

class AlertVoter extends Voter
{
    public const VIEW = 'ALERT_VIEW';
    public const EDIT_COLLECTE = 'ALERT_EDIT_COLLECTE';
    public const EDIT_QUALIFICATION = 'ALERT_EDIT_QUALIFICATION';
    public const DECIDE = 'ALERT_DECIDE';
    public const EXPORT = 'ALERT_EXPORT';
    public const QUALIFY = 'ALERT_QUALIFY';
    public const TRANSMIT = 'ALERT_TRANSMIT';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [
            self::VIEW,
            self::EDIT_COLLECTE,
            self::EDIT_QUALIFICATION,
            self::DECIDE,
            self::EXPORT,
            self::QUALIFY,
            self::TRANSMIT,
        ]) && $subject instanceof Alert;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        /** @var Alert $alert */
        $alert = $subject;

        // SUPERADMIN : accès total à toutes les opérations métier (y compris qualification)
        if ($user->getRole() === UserRoleEnum::SUPERADMIN) {
            return true;
        }

        // SAHOLTY : Vue régionale complète, qualification et décision sur toutes les alertes
        if ($user->getRole() === UserRoleEnum::SAHOLTY) {
            return true;
        }

        // COMITE_AIT / SECRETARIAT_GEI : vue et export uniquement
        if (in_array($user->getRole(), [UserRoleEnum::COMITE_AIT, UserRoleEnum::SECRETARIAT_GEI], true)) {
            return in_array($attribute, [self::VIEW, self::EXPORT], true);
        }

        return match ($attribute) {
            self::VIEW => $this->canView($alert, $user),
            self::EDIT_COLLECTE => $this->canEditCollecte($alert, $user),
            self::EDIT_QUALIFICATION, self::QUALIFY => $this->canEditQualification($alert, $user),
            self::DECIDE, self::TRANSMIT => $this->canDecide($alert, $user),
            self::EXPORT => $this->canExport($alert, $user),
            default => false,
        };
    }

    private function canView(Alert $alert, User $user): bool
    {
        // SUPERADMIN et SAHOLTY : vus plus haut, toujours true
        // COMITE_AIT / SECRETARIAT_GEI : vue globale
        if (in_array($user->getRole(), [UserRoleEnum::COMITE_AIT, UserRoleEnum::SECRETARIAT_GEI], true)) {
            return true;
        }

        // MANAGER (PFT) : Voit les alertes de TOUS ses marchés gérés
        if ($user->getRole() === UserRoleEnum::PFT) {
            $managedMarkets = $user->getAllManagedMarkets();
            return in_array($alert->getMarket(), $managedMarkets, true);
        }

        // AGENT : Ne voit QUE ses propres alertes
        if ($user->getRole() === UserRoleEnum::EMETTEUR_TERRAIN) {
            return $alert->getEmetteur()?->getId() === $user->getId();
        }

        return false;
    }

    private function canEditCollecte(Alert $alert, User $user): bool
    {
        // MANAGER (PFT) : Droit d'édition TOTAL et permanent sur les alertes de ses marchés (Spec A.1)
        if ($user->getRole() === UserRoleEnum::PFT) {
            return in_array($alert->getMarket(), $user->getAllManagedMarkets(), true);
        }

        // AGENT : Uniquement ses propres alertes ET si statut brouillon/nouveau/a_completer
        if ($user->getRole() === UserRoleEnum::EMETTEUR_TERRAIN) {
            return $alert->getEmetteur()?->getId() === $user->getId()
                && in_array($alert->getStatut(), [AlertStatut::NOUVEAU, AlertStatut::A_COMPLETER], true);
        }

        return false;
    }

    private function canEditQualification(Alert $alert, User $user): bool
    {
        // Bloqué pour l'AGENT (EMETTEUR_TERRAIN)
        if ($user->getRole() === UserRoleEnum::EMETTEUR_TERRAIN) {
            return false;
        }

        // MANAGER (PFT) : Qualification sur ses marchés gérés
        if ($user->getRole() === UserRoleEnum::PFT) {
            return in_array($alert->getMarket(), $user->getAllManagedMarkets(), true);
        }

        return false;
    }

    private function canDecide(Alert $alert, User $user): bool
    {
        if ($user->getRole() === UserRoleEnum::EMETTEUR_TERRAIN) {
            return false;
        }

        if ($user->getRole() === UserRoleEnum::PFT) {
            return in_array($alert->getMarket(), $user->getAllManagedMarkets(), true);
        }

        return false;
    }

    private function canExport(Alert $alert, User $user): bool
    {
        return $this->canView($alert, $user);
    }
}
