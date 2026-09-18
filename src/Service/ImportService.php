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
use App\Enum\TypeLocalisation;
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
    private const ROWS_PER_BATCH = 100;
    private const REQUIRED_COLUMNS = [
        'id_gei', 'date', 'emetteur', 'zone', 'port', 'categorie', 'resume',
        'type_source', 'anonymisation', 'fiabilite', 'credibilite', 'urgence',
        'impact', 'exploitabilite', 'statut', 'transmission', 'actions', 'pieces',
        'reference', 'sensibilite', 'derniere_maj', 'responsable', 'commentaires',
    ];

    /** @var array<string, Market> Marchés créés mais pas encore flushés */
    private array $pendingMarkets = [];

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

    public static function detectTypeLocalisation(string $value): ?TypeLocalisation
    {
        $normalized = mb_strtolower(trim($value));
        $normalized = strtr($normalized, [
            'à' => 'a', 'â' => 'a', 'ä' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o',
            'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c',
        ]);

        if ($normalized === '') {
            return null;
        }

        if (preg_match('/\b(aeroport|airport|aerodrome|terminal\s+passagers?)\b/u', $normalized)) {
            return TypeLocalisation::AEROPORT;
        }

        if (preg_match('/\b(port|quai|terminal\s+portuaire|portuaire|harbour|harbor)\b/u', $normalized)) {
            return TypeLocalisation::PORT;
        }

        if (preg_match('/\b(corridor|axe|route|itineraire|frontiere|transit)\b/u', $normalized)
            || preg_match('/\s(?:-|–|—|\/|vers)\s/u', $normalized)) {
            return TypeLocalisation::CORRIDOR;
        }

        return null;
    }

    /**
     * Analyse un fichier Excel SANS écrire en base (dry-run).
     * Permet au Manager de valider avant confirmation.
     *
     * @return array{
     *   header_errors: string[],
     *   colonnes_manquantes: string[],
     *   lignes: array<int, array{line: int, id: string, statut: string, champs_manquants: string[], raison?: string}>,
     *   compteur: array{valide: int, a_corriger: int, doublon: int, erreur: int},
     *   total: int
     * }
     */
    public function dryRun(UploadedFile $file, User $currentUser): array
    {
        [$colMap, $headerErrors] = $this->readHeader($file->getPathname());

        if (!empty($headerErrors)) {
            return [
                'header_errors'       => $headerErrors,
                'colonnes_manquantes' => [],
                'lignes'              => [],
                'compteur'            => ['valide' => 0, 'a_corriger' => 0, 'doublon' => 0, 'erreur' => 0],
                'total'               => 0,
            ];
        }

        // ── Étape 2 : Analyse ligne par ligne (sans écriture) ──
        $lignes = [];
        $compteur = ['valide' => 0, 'a_corriger' => 0, 'doublon' => 0, 'erreur' => 0];

        foreach ($this->readRowsInChunks($file->getPathname(), $colMap) as [$rowNum, $row]) {

            $codeGei = trim($row[$colMap['id_gei'] ?? ''] ?? '');
            $resume  = trim($row[$colMap['resume'] ?? ''] ?? '');
            $zone    = trim($row[$colMap['zone'] ?? ''] ?? '');

            if (empty($codeGei) && empty($resume) && empty($zone)) {
                continue;
            }

            $ligne = [
                'line'             => $rowNum,
                'id'               => $codeGei ?: "(ligne $rowNum)",
                'statut'           => 'valide',
                'champs_manquants' => [],
            ];

            try {
                // Vérifier les doublons
                if (!empty($codeGei)) {
                    $existing = $this->alertRepository->findOneBy(['codeGei' => $codeGei]);
                    if (null !== $existing) {
                        $ligne['statut'] = 'doublon';
                        $ligne['raison'] = 'Ce code GEI existe déjà en base. L\'import mettra à jour l\'alerte existante.';
                        $compteur['doublon']++;
                        $lignes[] = $ligne;
                        continue;
                    }
                }

                // Analyser les champs obligatoires
                $champsManquants = [];
                $get = static fn(string $field) => isset($colMap[$field]) ? trim($row[$colMap[$field]] ?? '') : '';

                if (empty($get('resume'))) {
                    $champsManquants[] = 'resumeExecutif';
                }
                $fiab = $this->matchEnumWithAliases(FiabiliteSource::class, $get('fiabilite'));
                if (null === $fiab) {
                    $champsManquants[] = 'fiabiliteSource';
                }
                $credVal = (int)$get('credibilite');
                if ($credVal < 1 || $credVal > 4) {
                    $champsManquants[] = 'credibiliteContenu';
                }
                $urg = $this->matchEnumWithAliases(AlertUrgence::class, $get('urgence'));
                if (null === $urg) {
                    $champsManquants[] = 'urgence';
                }
                $imp = $this->matchEnumWithAliases(AlertImpact::class, $get('impact'));
                if (null === $imp) {
                    $champsManquants[] = 'impact';
                }
                $exp = $this->matchEnumWithAliases(AlertExploitabilite::class, $get('exploitabilite'));
                if (null === $exp) {
                    $champsManquants[] = 'exploitabilite';
                }
                if (empty($get('date'))) {
                    $champsManquants[] = 'dateCreation';
                }
                if (empty($zone)) {
                    $champsManquants[] = 'zone';
                }

                $ligne['champs_manquants'] = $champsManquants;

                if (!empty($champsManquants)) {
                    $ligne['statut'] = 'a_corriger';
                    $compteur['a_corriger']++;
                } else {
                    $compteur['valide']++;
                }

                $lignes[] = $ligne;

            } catch (\Exception $e) {
                $ligne['statut'] = 'erreur';
                $ligne['raison'] = $e->getMessage();
                $compteur['erreur']++;
                $lignes[] = $ligne;
            }
        }

        return [
            'header_errors'       => [],
            'colonnes_manquantes' => [],
            'lignes'              => $lignes,
            'compteur'            => $compteur,
            'total'               => count($lignes),
        ];
    }

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
        return $this->importPath($file->getPathname(), $currentUser);
    }

    /**
     * Importe un chemin déjà stocké, ce qui permet au worker Messenger de ne
     * jamais reconstruire un classeur temporaire par ligne.
     */
    public function importPath(string $path, User $currentUser, ?string $batchId = null): array
    {
        [$colMap, $headerErrors] = $this->readHeader($path);
        $batchId ??= uniqid('import_', true);

        if (!empty($headerErrors)) {
            return [
                'success_count' => 0,
                'update_count' => 0,
                'error_count' => count($headerErrors),
                'errors' => $headerErrors,
                'lignes_incompletes' => [],
                'batch_id' => $batchId,
            ];
        }

        $successCount      = 0;
        $updateCount       = 0;
        $errorCount        = 0;
        $errors            = [];
        $lignesIncompletes = [];
        $seenCodes         = [];

        foreach ($this->readRowsInChunks($path, $colMap) as [$rowNum, $row]) {

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
                    if (isset($seenCodes[$codeGei])) {
                        $errorCount++;
                        $errors[] = sprintf('Ligne %d (%s) : code GEI répété dans le fichier (déjà présent ligne %d).', $rowNum, $codeGei, $seenCodes[$codeGei]);
                        continue;
                    }
                    $seenCodes[$codeGei] = $rowNum;
                    $existing = $this->alertRepository->findOneBy(['codeGei' => $codeGei]);
                    if (null !== $existing) {
                        $existing->setOrigine('import_excel');
                        $existing->setImportBatchId($batchId);
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

                if (in_array('dateCreation', $champsManquants, true)) {
                    $errorCount++;
                    $errors[] = sprintf('Ligne %d (%s) : date absente ou invalide.', $rowNum, $codeGei ?: 'sans ID');
                    continue;
                }

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

                // Flush par lot : une erreur de contrainte est remontée avec son contexte.
                if (($successCount + $updateCount) % self::ROWS_PER_BATCH === 0) {
                    try {
                        $this->em->flush();
                        $this->em->clear();
                        $this->pendingMarkets = [];
                        if (null !== $currentUser->getId()) {
                            $currentUser = $this->userRepository->find($currentUser->getId()) ?? $currentUser;
                        }
                    } catch (\Throwable $e) {
                        throw new \RuntimeException(sprintf(
                            'Échec de validation du lot autour de la ligne %d : %s',
                            $rowNum,
                            $e->getMessage()
                        ), 0, $e);
                    }
                }

            } catch (\Exception $e) {
                if (!$this->em->isOpen()) {
                    throw $e;
                }
                $errorCount++;
                $errors[] = sprintf('Ligne %d (%s) : %s', $rowNum, $codeGei ?: 'sans ID', $e->getMessage());
            }
        }

        if (!$this->em->isOpen()) {
            throw new \RuntimeException('Doctrine a fermé l’EntityManager avant le dernier lot. Consultez la cause SQL précédente.', 0);
        }

        try {
            $this->em->flush();
        } catch (\Throwable $e) {
            throw new \RuntimeException('Échec de validation du dernier lot : ' . $e->getMessage(), 0, $e);
        }

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

    public function countDataRows(string $path): int
    {
        return max(0, $this->getHighestRow($path) - 1);
    }

    /** @return array{0: array<string, string>, 1: string[]} */
    private function readHeader(string $path): array
    {
        if (!is_file($path)) {
            return [[], ['Le fichier importé est introuvable.']];
        }

        try {
            $reader = IOFactory::createReader('Xlsx');
            $reader->setReadDataOnly(true);
            $reader->setReadFilter(new class implements \PhpOffice\PhpSpreadsheet\Reader\IReadFilter {
                public function readCell($columnAddress, $row, $worksheetName = ''): bool
                {
                    return $row === 1;
                }
            });
            $sheet = $reader->load($path)->getActiveSheet();
            [$colMap, $errors] = $this->detectColumns($sheet->toArray(null, true, true, true)[1] ?? null);
            return [$colMap, $errors];
        } catch (\Throwable $e) {
            return [[], ['Le fichier Excel est illisible : ' . $e->getMessage()]];
        }
    }

    /** @return iterable<array{0:int, 1:array}> */
    private function readRowsInChunks(string $path, array $colMap): iterable
    {
        $highestRow = $this->getHighestRow($path);
        for ($start = 2; $start <= $highestRow; $start += self::ROWS_PER_BATCH) {
            $end = min($highestRow, $start + self::ROWS_PER_BATCH - 1);
            $reader = IOFactory::createReader('Xlsx');
            $reader->setReadDataOnly(true);
            $reader->setReadFilter(new class($start, $end) implements \PhpOffice\PhpSpreadsheet\Reader\IReadFilter {
                public function __construct(private int $start, private int $end) {}

                public function readCell($columnAddress, $row, $worksheetName = ''): bool
                {
                    return $row >= $this->start && $row <= $this->end;
                }
            });
            $spreadsheet = $reader->load($path);
            foreach ($spreadsheet->getActiveSheet()->toArray(null, true, true, true) as $rowNum => $row) {
                if ($rowNum >= $start && $rowNum <= $end) {
                    yield [$rowNum, $row];
                }
            }
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet, $reader);
        }
    }

    private function getHighestRow(string $path): int
    {
        $reader = IOFactory::createReader('Xlsx');
        $reader->setReadDataOnly(true);
        $info = $reader->listWorksheetInfo($path);
        return (int) ($info[0]['totalRows'] ?? 1);
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

        $errors = [];

        foreach (self::REQUIRED_COLUMNS as $field) {
            if (!isset($colMap[$field])) {
                $errors[] = sprintf('Colonne obligatoire manquante ou mal nommée : "%s".', $field);
            }
        }

        return [$colMap, $errors];
    }

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
            $date = null;
            // Prise en charge des dates numériques natives Excel (ex: 46000)
            if (is_numeric($dateStr) && (float)$dateStr > 30000 && (float)$dateStr < 70000) {
                try {
                    $date = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float)$dateStr);
                } catch (\Throwable) {}
            }

            if (!$date) {
                $date = $this->parseImportDate($dateStr);
            }

            if ($date) {
                $alert->setDateCreation($date);
            } else {
                $manquants[] = 'dateCreation';
            }
        } else {
            $manquants[] = 'dateCreation';
        }

        // ── Port / Corridor ──────────────────────────────────────────────────────
        $portCorridor = $get('port') ?: null;
        $alert->setPortCorridor($portCorridor);
        $alert->setTypeLocalisation(null !== $portCorridor ? self::detectTypeLocalisation($portCorridor) : null);

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

    private function parseImportDate(string $dateStr): ?\DateTime
    {
        $formats = ['n/j/Y', 'd/m/Y', 'm/d/Y', 'Y-m-d', 'd/m/y', 'n/j/y', 'd.m.Y', 'Y/m/d'];
        foreach ($formats as $format) {
            $parsed = \DateTime::createFromFormat('!' . $format, $dateStr);
            $errors = \DateTime::getLastErrors();
            if ($parsed && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0)) && $parsed->format($format) === $dateStr) {
                return $parsed;
            }
        }

        try {
            $timestamp = strtotime($dateStr);
            return false !== $timestamp ? (new \DateTime())->setTimestamp($timestamp) : null;
        } catch (\Throwable) {
            return null;
        }
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
            if (isset($this->pendingMarkets[$iso3])) {
                return $this->pendingMarkets[$iso3];
            }
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
        if (isset($this->pendingMarkets[$rawIso ?: 'NDF'])) {
            return $this->pendingMarkets[$rawIso ?: 'NDF'];
        }
        $newMarket->setCodeIso3($rawIso ?: 'NDF');
        $newMarket->setNom(explode('/', $zoneNom)[0]);
        $this->em->persist($newMarket);
        $this->pendingMarkets[$rawIso ?: 'NDF'] = $newMarket;
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
