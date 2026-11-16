<?php

namespace App\Security;

use App\Entity\User;
use App\Enum\UserRoleEnum;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Contrôle d'accès granulaire pour le module géospatial GEI.
 *
 * Attributs disponibles :
 *   GEO_VIEW         → accéder aux pages et cartes géospatiales
 *   GEO_API          → appeler les endpoints JSON/GeoJSON
 *   GEO_EXPORT       → exporter les données géospatiales
 *   GEO_ADMIN        → configurer les paramètres du module (réservé SUPERADMIN/SAHOLTY)
 *
 * RÈGLE ABSOLUE : EMETTEUR_TERRAIN est toujours refusé, sans exception.
 * SECRETARIAT_GEI et COMITE_AIT ont un accès lecture seule (view + api).
 */
final class GeoVoter extends Voter
{
    public const GEO_VIEW   = 'GEO_VIEW';
    public const GEO_API    = 'GEO_API';
    public const GEO_EXPORT = 'GEO_EXPORT';
    public const GEO_ADMIN  = 'GEO_ADMIN';

    private const SUPPORTED_ATTRIBUTES = [
        self::GEO_VIEW,
        self::GEO_API,
        self::GEO_EXPORT,
        self::GEO_ADMIN,
    ];

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, self::SUPPORTED_ATTRIBUTES, true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        // EMETTEUR_TERRAIN : accès interdit sans exception
        if ($user->getRole() === UserRoleEnum::EMETTEUR_TERRAIN) {
            return false;
        }

        // Utilisateur inactif : accès refusé
        if (!$user->isActif()) {
            return false;
        }

        return match ($attribute) {
            self::GEO_VIEW   => $this->canView($user),
            self::GEO_API    => $this->canCallApi($user),
            self::GEO_EXPORT => $this->canExport($user),
            self::GEO_ADMIN  => $this->canAdmin($user),
            default          => false,
        };
    }

    /**
     * Accès aux pages HTML du module géospatial.
     * PFT, SAHOLTY, SUPERADMIN, SECRETARIAT_GEI, COMITE_AIT.
     * EMETTEUR_TERRAIN toujours exclu (géré ci-dessus).
     */
    private function canView(User $user): bool
    {
        return in_array($user->getRole(), [
            UserRoleEnum::SUPERADMIN,
            UserRoleEnum::SAHOLTY,
            UserRoleEnum::PFT,
            UserRoleEnum::SECRETARIAT_GEI,
            UserRoleEnum::COMITE_AIT,
        ], true);
    }

    /**
     * Accès aux endpoints API JSON/GeoJSON.
     * Mêmes règles que canView — toute page visible implique ses API.
     */
    private function canCallApi(User $user): bool
    {
        return $this->canView($user);
    }

    /**
     * Export des données géospatiales (CSV, PDF).
     * Réservé aux rôles opérationnels — COMITE_AIT exclut (vue stratégique uniquement).
     */
    private function canExport(User $user): bool
    {
        return in_array($user->getRole(), [
            UserRoleEnum::SUPERADMIN,
            UserRoleEnum::SAHOLTY,
            UserRoleEnum::PFT,
            UserRoleEnum::SECRETARIAT_GEI,
        ], true);
    }

    /**
     * Administration du module (configuration des seuils, règles).
     * SUPERADMIN et SAHOLTY uniquement.
     */
    private function canAdmin(User $user): bool
    {
        return in_array($user->getRole(), [
            UserRoleEnum::SUPERADMIN,
            UserRoleEnum::SAHOLTY,
        ], true);
    }
}
