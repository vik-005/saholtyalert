<?php

namespace App\Service;

use App\Entity\Alert;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Génère le code GEI unique pour chaque alerte.
 *
 * Format exact conforme au registre client (Annexe B + Partie C du prompt) :
 *   GEI-{ISO3_PAYS}-{ANNEE}-{SEQ:003}
 *
 * Exemples réels du registre client :
 *   GEI-BEN-2026-001, GEI-TGO-2026-007, GEI-GHA-2026-015, GEI-SEN-2026-001
 */
class AlertCodeGeneratorService
{
    /**
     * Table complète Pays → ISO3 (mise à jour après audit du registre réel Annexe B).
     * Tous les pays observés dans le fichier Excel réel sont présents.
     */
    private const PAYS_TO_ISO3 = [
        // Afrique de l'Ouest — CEDEAO
        'Bénin'                => 'BEN',
        'Benin'                => 'BEN',
        'Togo'                 => 'TGO',
        'Ghana'                => 'GHA',
        "Côte d'Ivoire"        => 'CIV',
        "Cote d'Ivoire"        => 'CIV',
        'Cote d Ivoire'        => 'CIV',
        'Mali'                 => 'MLI',
        'Niger'                => 'NER',
        // Pays supplémentaires observés dans le registre réel (audit 17/08/2026)
        'Sénégal'              => 'SEN',
        'Senegal'              => 'SEN',
        'Guinée'               => 'GIN',
        'Guinee'               => 'GIN',
        'Angola'               => 'AGO',
        'Cameroun'             => 'CMR',
        'RD Congo'             => 'COD',
        'RDC'                  => 'COD',
        'République Démocratique du Congo' => 'COD',
        'Burkina Faso'         => 'BFA',
        'Burkina'              => 'BFA',
        'Nigeria'              => 'NGA',
        'Nigéria'              => 'NGA',
        // Note : le registre utilise GEI-NIG- pour Niger (Konni)
        // mais ISO3 officiel est NER. GEI-NIG- est traité comme alias.
    ];

    /**
     * Alias d'ISO3 observés directement dans le registre
     * (codes déjà utilisés en production, à préserver tels quels).
     */
    private const ISO3_ALIASES = [
        'NIG' => 'NER', // GEI-NIG-2026-041 dans le fichier réel → préservé si déjà existant
        'RDC' => 'COD',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * Génère et retourne le code GEI pour l'alerte donnée.
     * Format : GEI-{ISO3}-{ANNEE}-{SEQ:003}
     */
    public function generate(Alert $alert, ?\DateTimeInterface $customDate = null): string
    {
        $iso3  = $alert->getMarket()?->getCodeIso3() ?? 'GEI';
        $date  = $customDate ?? $alert->getDateCreation() ?? new \DateTime();
        $annee = $date->format('Y');

        $sequence = $this->getNextSequence($iso3, $annee);

        return sprintf('GEI-%s-%s-%03d', $iso3, $annee, $sequence);
    }

    /**
     * Calcule le prochain numéro de séquence pour un pays et une année donnés.
     * Utilise FOR UPDATE pour protéger contre les créations simultanées.
     */
    public function getNextSequence(string $iso3, string $annee): int
    {
        $conn = $this->em->getConnection();

        $sql = '
            SELECT COALESCE(MAX(CAST(SUBSTRING(code_gei, -3) AS UNSIGNED)), 0) AS max_seq
            FROM alert
            WHERE code_gei LIKE :pattern
            FOR UPDATE
        ';

        $maxSeq = (int) $conn->fetchOne($sql, [
            'pattern' => sprintf('GEI-%s-%s-%%', $iso3, $annee),
        ]);

        return $maxSeq + 1;
    }

    /**
     * Convertit un nom de pays en code ISO3.
     * Utilisé lors de l'import Excel pour normaliser les zones.
     * Retourne null pour "Autre" (saisie manuelle du Manager requise).
     */
    public static function paysToIso3(string $pays): ?string
    {
        $pays = trim($pays);

        // Vérification directe insensible à la casse
        foreach (self::PAYS_TO_ISO3 as $nom => $iso3) {
            if (mb_strtolower($pays) === mb_strtolower($nom)) {
                return $iso3;
            }
        }

        // Correspondance partielle (ex. "Bénin / Nigeria" → "BEN")
        foreach (self::PAYS_TO_ISO3 as $nom => $iso3) {
            if (mb_stripos($pays, $nom) !== false) {
                return $iso3; // retourne le premier pays trouvé
            }
        }

        return null; // 'Autre' — code à saisir manuellement
    }

    /**
     * Retourne la table complète des correspondances pour utilisation externe.
     */
    public static function getPaysToIso3Map(): array
    {
        return self::PAYS_TO_ISO3;
    }
}
