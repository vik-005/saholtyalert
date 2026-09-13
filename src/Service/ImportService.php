<?php

namespace App\Service;

use App\Entity\Alert;
use App\Entity\Market;
use App\Entity\User;
use App\Enum\AlertExploitabilite;
use App\Enum\AlertImpact;
use App\Enum\AlertStatut;
use App\Enum\AlertUrgence;
use App\Enum\FiabiliteSource;
use App\Enum\Sensibilite;
use App\Enum\TransmissionStatut;
use App\Repository\AlertRepository;
use App\Repository\MarketRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Service d'importation du registre GEI depuis un fichier Excel (.xlsx).
 *
 * Fonctionnement post-audit (17/08/2026) :
 *  - Détection DYNAMIQUE des colonnes par intitulé (pas position fixe)
 *    → compatible avec le fichier réel où col A est vide
 *  - Mapping des valeurs Enum avec alias (ex. "72 h" → AlertUrgence::SOIXANTE_DOUZE_H)
 *  - ISO3 étendus : SEN, GIN, AGO, CMR, COD, NGA, BFA
 *  - Signalement des champs null post-mapping (lignes incomplètes)
 *  - Préservation de l'ID GEI existant (jamais réécrit)
 */
class ImportService
{
    /**
     * Fragments d'intitulés attendus pour la détection dynamique des colonnes.
     * La recherche est insensible à la casse et partielle (str_contains).
     * Ordre de priorité : le premier match dans la ligne d'en-tête est retenu.
     */
    private const COLUMN_MAP = [
        'id_gei'          => ['id gei', 'identifiant gei', 'code gei'],
        'date'            => ['date'],
        'emetteur'        => ['émetteur', 'emetteur'],
        'zone'            => ['zone', 'pays'],
        'port'            => ['port', 'corridor'],
        'categorie'       => ['catégorie', 'categorie'],
        'resume'          => ['résumé', 'resume'],
        'type_source'     => ['type de source', 'source'],
        'anonymisation'   => ['anonymisation'],
        'fiabilite'       => ['fiabilité', 'fiabilite'],
        'credibilite'     => ['crédibilité', 'credibilite'],
        'urgence'         => ['urgence'],
        'impact'          => ['impact'],
        'exploitabilite'  => ['exploitabilité', 'exploitabilite'],
        'statut'          => ['statut'],
        'transmission'    => ['transmission'],
        'actions'         => ['actions en cours', 'actions'],
        'pieces'          => ['pièces', 'pieces'],
        'reference'       => ['référence documentaire', 'reference documentaire', 'référence', 'reference'],
        'sensibilite'     => ['sensibilité', 'sensibilite'],
        'derniere_maj'    => ['dernière', 'derniere', 'maj'],
        'responsable'     => ['responsable suivi', 'responsable'],
        'commentaires'    => ['commentaires', 'commentaire'],
    ];

    /**
     * Alias de valeurs observés dans le registre réel.
     * Permet de mapper des variantes textuelles vers les valeurs Enum exactes.
     */
    private const ENUM_ALIASES = [
        // Urgence — "72 h" avec espace vs "72h"
        AlertUrgence::class => [
            '72 h'     => '72h',
            '72H'      => '72h',
            'immédiat' => 'immediat',
            'immediate'=> 'immediat',
        ],
        // Impact : les anciennes variantes sont normalisées vers les trois niveaux GEI.
        AlertImpact::class => [
            'très élevé'   => 'eleve',
            'tres eleve'   => 'eleve',
            'moyen à élevé'=> 'moyen',
            'moyen a eleve'=> 'moyen',
            'élevé'        => 'eleve',
            'moyen'        => 'moyen',
            'faible'       => 'faible',
        ],
        // Exploitabilité
        AlertExploitabilite::class => [
            'exploitable'  => 'exploitable',
            'à analyser'   => 'a_analyser',
            'a analyser'   => 'a_analyser',
            'à surveiller' => 'a_surveiller',
            'a surveiller' => 'a_surveiller',
            'à compléter'  => 'a_completer',
            'a completer'  => 'a_completer',
            'actionnable'  => 'actionnable',
            'archivage'    => 'archivage',
        ],
        // Statut
        AlertStatut::class => [
            'ouvert'                    => 'ouvert',
            "en cours d'analyse"        => 'en_cours_analyse',
            "en cours d analyse"        => 'en_cours_analyse',
            'en cours'                  => 'en_cours',
            'ouvert – prioritaire'      => 'ouvert_prioritaire',
            'ouvert - prioritaire'      => 'ouvert_prioritaire',
            'transmis'                  => 'transmis',
            'suivi'                     => 'suivi',
            'clos'                      => 'clos',
            'archivé'                   => 'archive',
            'archive'                   => 'archive',
            'à investiguer'             => 'a_investiguer',
            'a investiguer'             => 'a_investiguer',
            'à compléter'               => 'a_completer',
            'a completer'               => 'a_completer',
            'à valider'                 => 'a_valider_saholty',
            'à valider (saholty)'       => 'a_valider_saholty',
        ],
        // Catégorie — stockée tel quelle (liste administrable)
        // Plus de mapping vers enum : la valeur brute est conservée
        // TypeSource — stocké tel quel (liste administrable)
    ];

    public function __construct(
        private readonly EntityManagerInterface    $em,
        private readonly MarketRepository          $marketRepository,
        private readonly UserRepository            $userRepository,
        private readonly AlertRepository           $alertRepository,
        private readonly ScoreCalculatorService    $scoreCalculator,
        private readonly AlertCodeGeneratorService $codeGenerator,
    ) {}

    /**
     * Importe les alertes depuis un fichier Excel.
     * Retourne un rapport détaillé avec lignes_incompletes pour suivi Manager.
     *
     * @return array{
     *   success_count: int,
     *   update_count: int,
     *   error_count: int,
     *   errors: array<int, string>,
     *   lignes_incompletes: array<int, array{line: int, id: string, champs_manquants: string[]}>,
     *   batch_id: string
     * }
     */
    public function importExcel(UploadedFile $file, User $currentUser): array
    {
        $spreadsheet = IOFactory::load($file->getPathname());
        $sheet       = $spreadsheet->getActiveSheet();
        // toArray retourne un tableau 1-indexed (ligne 1 = première ligne du fichier)
        $rows = $sheet->toArray(null, true, true, true);

        $batchId           = uniqid('import_', true);
        $successCount      = 0;
        $updateCount       = 0;
        $errorCount        = 0;
        $errors            = [];
        $lignesIncompletes = [];

        // ── Étape 1 : Trouver la ligne d'en-tête ────────────────────────────────
        // Cherche la première ligne qui contient les mots-clés d'en-tête.
        // Gère les fichiers avec ou sans ligne de titre.
        $headerRowNum = null;
        foreach ($rows as $rowNum => $row) {
            $rowText = mb_strtolower(implode(' ', array_filter($row, fn($v) => $v !== null && $v !== '')));
            if (
                str_contains($rowText, 'id gei') ||
                (str_contains($rowText, 'résumé') && str_contains($rowText, 'zone')) ||
                (str_contains($rowText, 'date') && str_contains($rowText, 'statut'))
            ) {
                $headerRowNum = $rowNum;
                break;
            }
        }

        if ($headerRowNum === null) {
            // Aucun en-tête reconnu — prendre la ligne 1 par défaut
            $headerRowNum = array_key_first($rows);
        }

        $headerRow = $rows[$headerRowNum];
        [$colMap, $headerErrors] = $this->detectColumns($headerRow);

        if (!empty($headerErrors)) {
            return [
                'success_count'     => 0,
                'update_count'      => 0,
                'error_count'       => count($headerErrors),
                'errors'            => $headerErrors,
                'lignes_incompletes'=> [],
                'batch_id'          => $batchId,
            ];
        }

        // ── Étape 2 : Traitement des lignes de données ──────────────────────────
        // On commence APRÈS la ligne d'en-tête
        foreach ($rows as $rowNum => $row) {
            if ($rowNum <= $headerRowNum) {
                continue; // ignorer l'en-tête et les lignes avant
            }

            // Ligne vide → ignorer
            $codeGei = trim($row[$colMap['id_gei'] ?? ''] ?? '');
            $resume  = trim($row[$colMap['resume'] ?? ''] ?? '');
            $zone    = trim($row[$colMap['zone'] ?? ''] ?? '');

            if (empty($codeGei) && empty($resume) && empty($zone)) {
                continue;
            }

            try {
                $champsManquants = [];

                // ── Doublon sur codeGei → mise à jour ─────────────────────────
                if (!empty($codeGei)) {
                    $existing = $this->alertRepository->findOneBy(['codeGei' => $codeGei]);
                    if (null !== $existing) {
                        $manquants = $this->fillAlertFromRow($existing, $row, $colMap, $currentUser);
                        $this->scoreCalculator->calculate($existing);
                        if (!empty($manquants)) {
                            $lignesIncompletes[] = [
                                'line'             => $rowNum,
                                'id'               => $codeGei,
                                'champs_manquants' => $manquants,
                                'alert_id'         => $existing->getId(),
                            ];
                        }
                        $updateCount++;
                        continue;
                    }
                }

                // ── Nouvelle alerte ───────────────────────────────────────────
                $market = $this->resolveMarket($zone);
                $alert  = new Alert();
                $alert->setEmetteur($currentUser);
                $alert->setMarket($market);
                $alert->setOrigine('import_excel');
                $alert->setImportBatchId($batchId);

                // Conserver le texte émetteur original pour traçabilité
                $emetteurTexte = trim($row[$colMap['emetteur'] ?? ''] ?? '');
                if (!empty($emetteurTexte)) {
                    $alert->setEmetteurTexte($emetteurTexte);
                }

                $champsManquants = $this->fillAlertFromRow($alert, $row, $colMap, $currentUser);

                // Préserver l'ID GEI existant ou en générer un
                if (!empty($codeGei)) {
                    $alert->setCodeGei($codeGei);
                } else {
                    $alert->setCodeGei($this->codeGenerator->generate($alert));
                }

                // Score recalculé systématiquement
                if ($alert->isScoreable()) {
                    $this->scoreCalculator->calculate($alert);
                }

                $this->em->persist($alert);
                $successCount++;

                if (!empty($champsManquants)) {
                    $lignesIncompletes[] = [
                        'line'             => $rowNum,
                        'id'               => $codeGei ?: "(ligne $rowNum)",
                        'champs_manquants' => $champsManquants,
                        'alert_ref'        => $alert,
                    ];
                }

                // Flush par batch de 50 — SANS em->clear() pour ne pas détacher les Markets créés
                if ($successCount % 50 === 0) {
                    $this->em->flush();
                }

            } catch (\Exception $e) {
                $errorCount++;
                $errors[] = sprintf('Ligne %d (%s) : %s', $rowNum, $codeGei ?: 'sans ID', $e->getMessage());
            }
        }

        $this->em->flush();

        // Résoudre les IDs après flush final
        foreach ($lignesIncompletes as &$li) {
            if (isset($li['alert_ref'])) {
                $li['alert_id'] = $li['alert_ref']->getId();
                unset($li['alert_ref']);
            }
        }

        return [
            'success_count'      => $successCount,
            'update_count'       => $updateCount,
            'error_count'        => $errorCount,
            'errors'             => $errors,
            'lignes_incompletes' => $lignesIncompletes,
            'batch_id'           => $batchId,
        ];
    }

    /**
     * Détecte dynamiquement les colonnes de la ligne d'en-tête.
     * Compatible avec les fichiers où col A est vide (registre réel).
     *
     * @return array{array<string,string>, string[]} [$colMap, $errors]
     */
    private function detectColumns(?array $headerRow): array
    {
        if (null === $headerRow) {
            return [[], ['Le fichier est vide ou ne contient pas de ligne d\'en-tête.']];
        }

        $colMap = [];

        // Construire un index inversé : valeur_entete_minuscule → lettre_colonne
        $headerIndex = [];
        foreach ($headerRow as $col => $val) {
            if ($val !== null && $val !== '') {
                $headerIndex[mb_strtolower(trim((string)$val))] = $col;
            }
        }

        // Pour chaque champ attendu, chercher dans les fragments
        foreach (self::COLUMN_MAP as $field => $fragments) {
            foreach ($fragments as $fragment) {
                // Correspondance exacte d'abord
                if (isset($headerIndex[$fragment])) {
                    $colMap[$field] = $headerIndex[$fragment];
                    break;
                }
                // Correspondance partielle
                foreach ($headerIndex as $headerVal => $col) {
                    if (str_contains($headerVal, $fragment)) {
                        $colMap[$field] = $col;
                        break 2;
                    }
                }
            }
        }

        // Seul le résumé est vraiment obligatoire — les autres seront null si absents
        $errors = [];

        // Validation structurelle minimale : au moins ID GEI ou Résumé doit être trouvé
        if (!isset($colMap['resume']) && !isset($colMap['id_gei'])) {
            $errors[] = 'Structure non reconnue : impossible de trouver les colonnes "ID GEI" ou "Résumé" dans l\'en-tête. '
                . 'Vérifiez que la ligne d\'en-tête est présente dans le fichier (colonnes attendues : ID GEI, Date, Zone, Résumé court…).';
            return [$colMap, $errors];
        }

        // Avertissements sur colonnes attendues mais non trouvées (non bloquants)
        $colonnesAttendues = ['zone', 'categorie', 'urgence', 'impact', 'exploitabilite', 'statut', 'fiabilite', 'credibilite'];
        $colonnesManquantes = [];
        foreach ($colonnesAttendues as $champ) {
            if (!isset($colMap[$champ])) {
                $colonnesManquantes[] = $champ;
            }
        }
        if (!empty($colonnesManquantes)) {
            // Non bloquant — les champs seront null et signalés en lignes incomplètes
            $errors[] = 'Avertissement : colonnes non trouvées dans l\'en-tête (seront nulles à l\'import) : '
                . implode(', ', $colonnesManquantes)
                . '. L\'import continuera mais les lignes concernées seront marquées comme incomplètes.';
        }

        return [$colMap, $errors];    }

    /**
     * Remplit une alerte depuis une ligne Excel avec le colMap dynamique.
     * Retourne la liste des champs obligatoires manquants (pour le rapport d'incomplétude).
     *
     * @return string[] Champs manquants ou non mappés
     */
    private function fillAlertFromRow(Alert $alert, array $row, array $colMap, User $currentUser): array
    {
        $manquants = [];

        $get = static fn(string $field) => isset($colMap[$field]) ? trim($row[$colMap[$field]] ?? '') : '';

        // ── Date ────────────────────────────────────────────────────────────────
        $dateStr = $get('date');
        if (!empty($dateStr)) {
            // Tester les formats les plus courants. Le fichier réel utilise m/d/Y (ex. 1/5/2026 = 5 janvier)
            // mais aussi d/m/Y selon l'origine. On détecte par la cohérence du résultat.
            $date = null;
            $formats = ['n/j/Y', 'd/m/Y', 'm/d/Y', 'Y-m-d', 'd/m/y', 'n/j/y'];
            foreach ($formats as $fmt) {
                $parsed = \DateTime::createFromFormat($fmt, $dateStr);
                if ($parsed && $parsed->format($fmt) === $dateStr) {
                    $date = $parsed;
                    break;
                }
            }
            // Fallback : strtotime
            if (!$date && strtotime($dateStr)) {
                $date = new \DateTime($dateStr);
            }
            if ($date) {
                $alert->setDateCreation($date);
            }
        }

        // ── Port / Corridor ──────────────────────────────────────────────────────
        $alert->setPortCorridor($get('port') ?: null);

        // ── Catégorie ────────────────────────────────────────────────────────────
        $catRaw = trim($get('categorie'));
        $alert->setCategorie($catRaw ?: null);

        // ── Résumé ───────────────────────────────────────────────────────────────
        $resume = $get('resume');
        if (empty($resume)) {
            $resume = 'Import automatique du registre';
            $manquants[] = 'resumeExecutif';
        }
        $alert->setResumeExecutif(mb_substr($resume, 0, 500));

        // ── Type de source ───────────────────────────────────────────────────────
        $typeSourceRaw = trim($get('type_source'));
        $alert->setTypeSource($typeSourceRaw ?: null);

        // ── Anonymisation (Oui / Non) ─────────────────────────────────────────
        $anonRaw = mb_strtolower(trim($get('anonymisation')));
        $anon = in_array($anonRaw, ['oui', 'yes', '1', 'true', 'élevé', 'eleve', 'élevé (par défaut)'])
            ? 'oui'
            : 'non';
        $alert->setAnonymisation($anon);

        // ── Fiabilité (A–D) ──────────────────────────────────────────────────────
        $fiab = $this->matchEnumWithAliases(FiabiliteSource::class, $get('fiabilite'));
        $alert->setFiabiliteSource($fiab);
        if (null === $fiab) {
            $manquants[] = 'fiabiliteSource';
        }

        // ── Crédibilité (1–4) ────────────────────────────────────────────────────
        $credVal = (int)$get('credibilite');
        if ($credVal >= 1 && $credVal <= 4) {
            $alert->setCredibiliteContenu($credVal);
        } else {
            $manquants[] = 'credibiliteContenu';
        }

        // ── Urgence ──────────────────────────────────────────────────────────────
        $urg = $this->matchEnumWithAliases(AlertUrgence::class, $get('urgence'));
        $alert->setUrgence($urg);
        if (null === $urg) {
            $manquants[] = 'urgence';
        }

        // ── Impact ───────────────────────────────────────────────────────────────
        $imp = $this->matchEnumWithAliases(AlertImpact::class, $get('impact'));
        $alert->setImpact($imp);
        if (null === $imp) {
            $manquants[] = 'impact';
        }

        // ── Exploitabilité ───────────────────────────────────────────────────────
        $exp = $this->matchEnumWithAliases(AlertExploitabilite::class, $get('exploitabilite'));
        $alert->setExploitabilite($exp);
        if (null === $exp) {
            $manquants[] = 'exploitabilite';
        }

        // ── Statut ───────────────────────────────────────────────────────────────
        $stat = $this->matchEnumWithAliases(AlertStatut::class, $get('statut'));
        $alert->setStatut($stat ?? AlertStatut::EN_COURS);

        // ── Transmission ─────────────────────────────────────────────────────────
        $txRaw = mb_strtolower($get('transmission'));
        $tx = match(true) {
            in_array($txRaw, ['oui', 'transmis', 'clôturé', 'clotyre']) => TransmissionStatut::OUI,
            in_array($txRaw, ['à valider', 'a valider'])                => TransmissionStatut::A_VALIDER,
            default                                                      => TransmissionStatut::NON,
        };
        $alert->setTransmission($tx);

        // ── Actions en cours ─────────────────────────────────────────────────────
        $alert->setActionsEnCours($get('actions') ?: null);

        // ── Pièces disponibles + type ─────────────────────────────────────────────
        $piecesRaw = $get('pieces');
        $alert->setPiecesDisponibles(str_starts_with(mb_strtolower($piecesRaw), 'oui'));
        // Stocker le type de pièce (ex. "Oui – manifeste")
        if (str_contains(mb_strtolower($piecesRaw), '–') || str_contains($piecesRaw, '-')) {
            $parts = preg_split('/[–\-]/', $piecesRaw, 2);
            $piecesType = trim($parts[1] ?? '');
            if (!empty($piecesType)) {
                $alert->setPiecesType($piecesType);
            }
        }

        // ── Référence documentaire ────────────────────────────────────────────────
        $alert->setReferenceDocumentaire($get('reference') ?: null);

        // ── Sensibilité ───────────────────────────────────────────────────────────
        $sens = $this->matchEnumWithAliases(Sensibilite::class, $get('sensibilite'));
        $alert->setSensibilite($sens ?? Sensibilite::RESTREINTE);

        // ── Responsable suivi ─────────────────────────────────────────────────────
        $responsableNom = $get('responsable');
        if (!empty($responsableNom)) {
            $responsable = $this->userRepository->createQueryBuilder('u')
                ->where('LOWER(CONCAT(u.prenom, \' \', u.nom)) LIKE LOWER(:nom)')
                ->orWhere('LOWER(u.nom) LIKE LOWER(:nom2)')
                ->setParameter('nom', '%' . $responsableNom . '%')
                ->setParameter('nom2', '%' . $responsableNom . '%')
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();
            if ($responsable) {
                $alert->setResponsableSuivi($responsable);
            }
        }

        // ── Commentaires ──────────────────────────────────────────────────────────
        $alert->setCommentaires($get('commentaires') ?: null);

        return $manquants;
    }

    /**
     * Résout un marché à partir du nom de zone.
     * Gère les zones composites comme "Bénin / Nigeria" → prend le premier pays.
     */
    private function resolveMarket(string $zoneNom): Market
    {
        if (empty($zoneNom)) {
            return $this->getDefaultMarket();
        }

        $iso3 = AlertCodeGeneratorService::paysToIso3($zoneNom);
        if ($iso3) {
            $m = $this->marketRepository->findOneBy(['codeIso3' => $iso3]);
            if ($m) return $m;
        }

        // Recherche partielle sur nom
        $m = $this->marketRepository->createQueryBuilder('m')
            ->where('LOWER(m.nom) LIKE LOWER(:nom) OR LOWER(m.codeIso3) LIKE LOWER(:iso)')
            ->setParameter('nom', '%' . explode('/', $zoneNom)[0] . '%')
            ->setParameter('iso', '%' . ($iso3 ?? '') . '%')
            ->getQuery()
            ->getOneOrNullResult();
        if ($m) return $m;

        // Création automatique d'un market placeholder
        $newMarket = new Market();
        $rawIso = $iso3 ?? strtoupper(mb_substr(preg_replace('/[^a-zA-Z]/', '', $zoneNom), 0, 3));
        $newMarket->setCodeIso3($rawIso ?: 'NDF');
        $newMarket->setNom(explode('/', $zoneNom)[0]);
        $this->em->persist($newMarket);
        return $newMarket;
    }

    private function getDefaultMarket(): Market
    {
        return $this->marketRepository->findOneBy(['codeIso3' => 'BEN'])
            ?? $this->marketRepository->findAll()[0];
    }

    /**
     * Match Enum avec prise en charge des alias du registre réel.
     * Ordre de résolution :
     *  1. Alias explicites (table ENUM_ALIASES)
     *  2. Valeur backing directe (FiabiliteSource::from('A'))
     *  3. Correspondance label() insensible à la casse
     */
    private function matchEnumWithAliases(string $enumClass, string $val): mixed
    {
        $val = trim($val);
        if ('' === $val) return null;

        $valLower = mb_strtolower($val);

        // 1. Alias explicites
        if (isset(self::ENUM_ALIASES[$enumClass])) {
            foreach (self::ENUM_ALIASES[$enumClass] as $alias => $target) {
                if ($valLower === $alias || str_contains($valLower, $alias)) {
                    try {
                        return $enumClass::from($target);
                    } catch (\ValueError) {}
                }
            }
        }

        // 2. Valeur backing directe (insensible à la casse)
        foreach ($enumClass::cases() as $case) {
            if (mb_strtolower($case->value) === $valLower) {
                return $case;
            }
        }

        // 3. Label insensible à la casse
        foreach ($enumClass::cases() as $case) {
            if (method_exists($case, 'label') && mb_strtolower($case->label()) === $valLower) {
                return $case;
            }
        }

        // 4. Correspondance partielle sur le label (pour les variantes du texte réel)
        foreach ($enumClass::cases() as $case) {
            if (method_exists($case, 'label') && str_contains($valLower, mb_strtolower($case->label()))) {
                return $case;
            }
            if (str_contains($valLower, mb_strtolower($case->value))) {
                return $case;
            }
        }

        return null;
    }
}
