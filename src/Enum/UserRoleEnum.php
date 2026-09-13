<?php

namespace App\Enum;

enum UserRoleEnum: string
{
    case SUPERADMIN = 'ROLE_SUPERADMIN';
    case SECRETARIAT_GEI = 'ROLE_SECRETARIAT_GEI';
    case SAHOLTY = 'ROLE_SAHOLTY';
    case PFT = 'ROLE_PFT';
    case EMETTEUR_TERRAIN = 'ROLE_EMETTEUR_TERRAIN';
    case COMITE_AIT = 'ROLE_COMITE_AIT';

    public function label(): string
    {
        return match($this) {
            self::SUPERADMIN => 'Super Administrateur',
            self::SECRETARIAT_GEI => 'Secrétariat GEI',
            self::SAHOLTY => 'Analyste SAHOLTY',
            self::PFT => 'Point Focal Technique (PFT)',
            self::EMETTEUR_TERRAIN => 'Émetteur Terrain',
            self::COMITE_AIT => 'Comité AIT',
        };
    }

    public function description(): string
    {
        return match($this) {
            self::SUPERADMIN => 'Gestion complète des utilisateurs, rôles et configuration système',
            self::SECRETARIAT_GEI => 'Enregistrement et mise à jour du registre, pas de pouvoir de décision',
            self::SAHOLTY => 'Analyse régionale, validation des transmissions externes, vue multi-pays',
            self::PFT => 'Qualification, validation et coordination au niveau marché',
            self::EMETTEUR_TERRAIN => 'Création d\'alertes, vision limitée à son propre marché',
            self::COMITE_AIT => 'Vue KPI/stratégique, escalades critiques uniquement',
        };
    }

    public static function choices(): array
    {
        $choices = [];
        foreach (self::cases() as $case) {
            $choices[$case->label()] = $case->value;
        }
        return $choices;
    }

    /** Rôles Symfony associés — la hiérarchie est gérée dans security.yaml */
    public function symfonyRoles(): array
    {
        return match($this) {
            self::SUPERADMIN => ['ROLE_SUPERADMIN', 'ROLE_SAHOLTY', 'ROLE_SECRETARIAT_GEI', 'ROLE_COMITE_AIT', 'ROLE_PFT', 'ROLE_USER'],
            self::SAHOLTY => ['ROLE_SAHOLTY', 'ROLE_PFT', 'ROLE_USER'],
            self::PFT => ['ROLE_PFT', 'ROLE_USER'],
            self::SECRETARIAT_GEI => ['ROLE_SECRETARIAT_GEI', 'ROLE_USER'],
            self::COMITE_AIT => ['ROLE_COMITE_AIT', 'ROLE_USER'],
            self::EMETTEUR_TERRAIN => ['ROLE_EMETTEUR_TERRAIN', 'ROLE_USER'],
        };
    }
}
