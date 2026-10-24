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
    public const REJECT = 'ALERT_REJECT';

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
            self::REJECT,
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
            self::DECIDE => $this->canDecide($alert, $user),
            self::TRANSMIT => $this->canTransmit($alert, $user),
            self::REJECT => $this->canReject($alert, $user),
            self::EXPORT => $this->canExport($alert, $user),
            default => false,
        };
    }

    private function canView(Alert $alert, User $user): bool
    {
        // Rôles avec vue globale : tout utilisateur ayant au moins le rôle MANAGER (PFT, SAHOLTY, SUPERADMIN, COMITE_AIT, SECRETARIAT_GEI)
        // voit les alertes de TOUS les agents
        if ($user->getRole() !== UserRoleEnum::EMETTEUR_TERRAIN) {
            return true;
        }

        // AGENT TERRAIN : Ne voit QUE ses propres alertes (son propre espace dédié)
        return $alert->getEmetteur()?->getId() === $user->getId();
    }

    private function canEditCollecte(Alert $alert, User $user): bool
    {
        // MANAGER (PFT) : Droit d'édition sur les alertes
        if ($user->getRole() === UserRoleEnum::PFT) {
            return true;
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
        // MANAGER (PFT) : Qualification des alertes
        if ($user->getRole() === UserRoleEnum::PFT) {
            return true;
        }

        return false;
    }

    private function canReject(Alert $alert, User $user): bool
    {
        // Le Manager (PFT) peut rejeter une fiche
        if ($user->getRole() !== UserRoleEnum::PFT) {
            return false;
        }

        return true;
    }

    private function canDecide(Alert $alert, User $user): bool
    {
        // Le Manager (PFT) valide et décide des actions et de l'urgence 72h
        if ($user->getRole() === UserRoleEnum::PFT) {
            return true;
        }

        return in_array($user->getRole(), [UserRoleEnum::SAHOLTY, UserRoleEnum::SUPERADMIN], true);
    }

    private function canTransmit(Alert $alert, User $user): bool
    {
        return $this->canDecide($alert, $user)
            && $alert->getStatut() === AlertStatut::VALIDEE;
    }

    private function canExport(Alert $alert, User $user): bool
    {
        return $this->canView($alert, $user);
    }
}
