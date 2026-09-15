<?php

namespace App\Repository;

use App\Dto\AuditFilterDTO;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class AlertAuditRepository
{
    private const TTL = 300;

    public function __construct(
        private readonly Connection $conn,
        private readonly CacheInterface $cache,
    ) {}

    public function countByFilters(AuditFilterDTO $dto, User $user): int
    {
        $key = $dto->cacheKey('count', $user->getId());
        return $this->cache->get($key, function (ItemInterface $item) use ($dto, $user) {
            $item->expiresAfter(self::TTL);
            [$sql, $params, $types] = $this->buildBaseSql($dto, $user, 'COUNT(DISTINCT a.id) as total');
            $row = $this->conn->fetchAssociative($sql, $params, $types);
            return (int) ($row['total'] ?? 0);
        });
    }

    public function findPaginated(AuditFilterDTO $dto, User $user): array
    {
        $offset = ($dto->page - 1) * $dto->limit;
        // LIMIT/OFFSET doivent être des entiers littéraux pour MariaDB (pas de binding PDO)
        [$sql, $params, $types] = $this->buildBaseSql($dto, $user);
        $sql .= ' ORDER BY a.date_creation DESC LIMIT ' . (int) $dto->limit . ' OFFSET ' . (int) $offset;

        return $this->conn->fetchAllAssociative($sql, $params, $types);
    }

    public function aggregateStats(AuditFilterDTO $dto, User $user): array
    {
        $key = $dto->cacheKey('stats', $user->getId());
        return $this->cache->get($key, function (ItemInterface $item) use ($dto, $user) {
            $item->expiresAfter(self::TTL);

            [$baseSql, $baseParams, $baseTypes] = $this->buildBaseSql($dto, $user);

            // Total + score moyen + critiques + taux transmission
            $summarySql = "SELECT
                COUNT(*) as total,
                ROUND(AVG(COALESCE(base.scoreSurcharge, base.scoreGei)), 1) as score_moyen,
                SUM(CASE WHEN COALESCE(base.scoreSurcharge, base.scoreGei) >= 18 THEN 1 ELSE 0 END) as critiques,
                ROUND(SUM(CASE WHEN base.transmission = 'oui' THEN 1 ELSE 0 END) / COUNT(*) * 100, 1) as taux_transmission
            FROM ({$baseSql}) as base";

            $summary = $this->conn->fetchAssociative($summarySql, $baseParams, $baseTypes);

            // Par marché
            $parMarche = $this->conn->fetchAllAssociative(
                "SELECT m.nom, m.code_iso3, COUNT(*) as nb, ROUND(AVG(COALESCE(base.scoreSurcharge, base.scoreGei)), 1) as score_moyen
                 FROM ({$baseSql}) as base
                 JOIN market m ON m.id = base.market_id
                 GROUP BY m.id, m.nom, m.code_iso3
                 ORDER BY nb DESC",
                $baseParams,
                $baseTypes
            );

            // Par statut
            $parStatut = $this->conn->fetchAllAssociative(
                "SELECT statut, COUNT(*) as nb
                 FROM ({$baseSql}) as base
                 GROUP BY statut
                 ORDER BY nb DESC",
                $baseParams,
                $baseTypes
            );

            // Par niveau priorité (avec FIELD pour ordre correct)
            $parNiveauPriorite = $this->conn->fetchAllAssociative(
                "SELECT niveauPriorite, COUNT(*) as nb
                 FROM ({$baseSql}) as base
                 GROUP BY niveauPriorite
                 ORDER BY FIELD(niveauPriorite, 'critique', 'eleve', 'modere', 'faible') DESC",
                $baseParams,
                $baseTypes
            );

            // Par catégorie
            $parCategorie = $this->conn->fetchAllAssociative(
                "SELECT categorie, COUNT(*) as nb
                 FROM ({$baseSql}) as base
                 GROUP BY categorie
                 ORDER BY nb DESC",
                $baseParams,
                $baseTypes
            );

            // Par mois (évolution)
            $parMois = $this->conn->fetchAllAssociative(
                "SELECT DATE_FORMAT(dateCreation, '%Y-%m') as mois, COUNT(*) as nb
                 FROM ({$baseSql}) as base
                 GROUP BY mois
                 ORDER BY mois ASC",
                $baseParams,
                $baseTypes
            );

            // Par agent (émetteur)
            $parAgent = $this->conn->fetchAllAssociative(
                "SELECT CONCAT(u.prenom, ' ', u.nom) as agent, COUNT(*) as nb
                 FROM ({$baseSql}) as base
                 JOIN `user` u ON u.id = base.emetteur_id
                 GROUP BY u.id, agent
                 ORDER BY nb DESC",
                $baseParams,
                $baseTypes
            );

            // Par urgence
            $parUrgence = $this->conn->fetchAllAssociative(
                "SELECT urgence, COUNT(*) as nb
                 FROM ({$baseSql}) as base
                 GROUP BY urgence
                 ORDER BY nb DESC",
                $baseParams,
                $baseTypes
            );

            // Par exploitabilité
            $parExploitabilite = $this->conn->fetchAllAssociative(
                "SELECT exploitabilite, COUNT(*) as nb
                 FROM ({$baseSql}) as base
                 GROUP BY exploitabilite
                 ORDER BY nb DESC",
                $baseParams,
                $baseTypes
            );

            // Données cartographie (marchés avec alertes)
            $donneesCartographie = $this->conn->fetchAllAssociative(
                "SELECT m.id as market_id, m.nom, m.code_iso3, z.latitude, z.longitude,
                        COUNT(*) as nb,
                        SUBSTRING_INDEX(
                            GROUP_CONCAT(base.niveauPriorite ORDER BY FIELD(base.niveauPriorite, 'critique', 'eleve', 'modere', 'faible') ASC),
                            ',', 1
                        ) as max_priorite
                 FROM ({$baseSql}) as base
                 JOIN market m ON m.id = base.market_id
                 LEFT JOIN zone_geo z ON z.market_id = m.id
                 GROUP BY m.id, m.nom, m.code_iso3, z.latitude, z.longitude
                 ORDER BY nb DESC",
                $baseParams,
                $baseTypes
            );

            return [
                'total' => (int) ($summary['total'] ?? 0),
                'scoreMoyen' => $summary['score_moyen'] !== null ? (float) $summary['score_moyen'] : null,
                'critiques' => (int) ($summary['critiques'] ?? 0),
                'tauxTransmission' => $summary['taux_transmission'] !== null ? (float) $summary['taux_transmission'] : null,
                'parMarche' => $parMarche,
                'parStatut' => $parStatut,
                'parNiveauPriorite' => $parNiveauPriorite,
                'parCategorie' => $parCategorie,
                'parMois' => $parMois,
                'parAgent' => $parAgent,
                'parUrgence' => $parUrgence,
                'parExploitabilite' => $parExploitabilite,
                'donneesCartographie' => $donneesCartographie,
            ];
        });
    }

    public function findDistinctCorridors(array $marketIds): array
    {
        if (empty($marketIds)) {
            return [];
        }

        $inClause = implode(',', array_map('intval', $marketIds));
        $sql = "SELECT DISTINCT a.port_corridor as corridor
                FROM alert a
                WHERE a.deleted_at IS NULL
                  AND a.port_corridor IS NOT NULL
                  AND a.port_corridor != ''
                  AND a.market_id IN ({$inClause})
                ORDER BY a.port_corridor ASC
                LIMIT 100";

        return array_column(
            $this->conn->fetchAllAssociative($sql),
            'corridor'
        );
    }

    /**
     * Retourne les valeurs distinctes d'opérateur/acteur pour l'autocomplétion.
     * Optionnellement filtrée par un préfixe.
     */
    public function findDistinctOperateurs(?string $search = null, array $marketIds = []): array
    {
        $where = "WHERE a.deleted_at IS NULL AND a.operateur_acteur IS NOT NULL AND a.operateur_acteur != ''";

        if (!empty($marketIds)) {
            $inClause = implode(',', array_map('intval', $marketIds));
            $where .= " AND a.market_id IN ({$inClause})";
        }

        $params = [];
        if ($search !== null && $search !== '') {
            $where .= " AND LOWER(a.operateur_acteur) LIKE :search";
            $params['search'] = '%' . strtolower($search) . '%';
        }

        return array_column(
            $this->conn->fetchAllAssociative(
                "SELECT DISTINCT a.operateur_acteur as operateur, COUNT(*) as nb
                 FROM alert a
                 {$where}
                 GROUP BY a.operateur_acteur
                 ORDER BY nb DESC
                 LIMIT 20",
                $params
            ),
            'operateur'
        );
    }

    // ── Builder SQL ────────────────────────────────────────────────────────────

    /**
     * Construit la clause SQL de base et retourne [sql, params, types].
     *
     * CORRECTION DBAL : Les clauses IN() sur des tableaux utilisent des paramètres
     * nommés expansés manuellement pour éviter la conversion "Array" par PDO.
     * Les valeurs entières sont castées explicitement.
     * LIMIT/OFFSET ne sont PAS binds ici — ils sont injectés en littéraux dans findPaginated().
     */
    private function buildBaseSql(
        AuditFilterDTO $dto,
        User $user,
        string $select = 'a.id, a.code_gei AS codeGei, a.date_creation AS dateCreation, a.port_corridor AS portCorridor, a.categorie, a.type_source AS typeSource, a.anonymisation, a.fiabilite_source AS fiabiliteSource, a.credibilite_contenu AS credibiliteContenu, a.urgence, a.impact, a.exploitabilite, a.statut, a.transmission, a.actions_en_cours AS actionsEnCours, a.pieces_disponibles AS piecesDisponibles, a.reference_documentaire AS referenceDocumentaire, a.sensibilite, a.commentaires, a.score_gei AS scoreGei, COALESCE(a.score_surcharge, a.score_gei) AS score, a.niveau_priorite AS niveauPriorite, COALESCE(a.niveau_priorite_surcharge, a.niveau_priorite) AS priorite, a.score_surcharge AS scoreSurcharge, a.niveau_priorite_surcharge AS niveauPrioriteSurcharge, a.origine, a.type_alerte AS typeAlerte, a.historique_source AS historiqueSource, a.emetteur_texte AS emetteurTexte, a.pieces_type AS piecesType, a.commentaire_rejet AS commentaireRejet, a.marque, a.operateur_acteur AS operateurActeur, a.decision_gei AS decisionGei, a.emetteur_id AS emetteur_id, a.market_id AS market_id, m.nom AS pays, m.code_iso3 AS codeIso3, CONCAT(e.prenom, \' \', e.nom) AS agent, a.resume_executif AS resumeExecutif'
    ): array {
        $params = [];
        $types  = [];

        $sql = "SELECT {$select}
                FROM alert a
                INNER JOIN market m ON m.id = a.market_id
                LEFT JOIN `user` e ON e.id = a.emetteur_id
                WHERE a.deleted_at IS NULL";

        // ── Scope utilisateur ──────────────────────────────────────────────────
        if ($user->getRole() === \App\Enum\UserRoleEnum::EMETTEUR_TERRAIN) {
            $sql .= ' AND a.emetteur_id = :currentUserId';
            $params['currentUserId'] = $user->getId();
        } elseif ($user->getRole() === \App\Enum\UserRoleEnum::PFT) {
            $managedMarkets = $user->getAllManagedMarkets();
            if (!empty($managedMarkets)) {
                $managedIds = array_map('intval', array_map(fn($m) => $m->getId(), $managedMarkets));
                $sql .= $this->buildInClause('a.market_id', 'managedMarketIds', $managedIds, $params);
            }
        }
        // SUPERADMIN, SAHOLTY, COMITE_AIT, SECRETARIAT_GEI → pas de filtre scope

        // ── Filtres date ───────────────────────────────────────────────────────
        if ($dto->dateFrom) {
            $sql .= ' AND a.date_creation >= :dateFrom';
            $params['dateFrom'] = $dto->dateFrom->format('Y-m-d H:i:s');
        }
        if ($dto->dateTo) {
            $sql .= ' AND a.date_creation < :dateTo';
            $params['dateTo'] = $dto->dateTo->format('Y-m-d H:i:s');
        }

        // ── Filtres multi-sélection (expansion manuelle IN pour compatibilité PDO) ──
        if (!empty($dto->markets)) {
            $ids = array_map('intval', $dto->markets);
            $sql .= $this->buildInClause('a.market_id', 'marketIds', $ids, $params);
        }
        if (!empty($dto->corridors)) {
            $sql .= $this->buildInClause('a.port_corridor', 'corridors', $dto->corridors, $params);
        }
        if (!empty($dto->categories)) {
            $catClauses = [];
            foreach ($dto->categories as $i => $cat) {
                $key = 'cat_' . $i;
                $catClauses[] = "(a.categorie = :{$key} OR LOWER(a.categorie) LIKE :{$key}_like)";
                $params[$key] = $cat;
                $params[$key . '_like'] = '%' . strtolower($cat) . '%';
            }
            $sql .= ' AND (' . implode(' OR ', $catClauses) . ')';
        }
        if (!empty($dto->typeSources)) {
            $sql .= $this->buildInClause('a.type_source', 'typeSources', $dto->typeSources, $params);
        }
        if (!empty($dto->statuts)) {
            $sql .= $this->buildInClause('a.statut', 'statuts', $dto->statuts, $params);
        }
        if (!empty($dto->urgences)) {
            $sql .= $this->buildInClause('a.urgence', 'urgences', $dto->urgences, $params);
        }
        if (!empty($dto->niveauxPriorite)) {
            $placeholders = [];
            foreach ($dto->niveauxPriorite as $i => $v) {
                $key = 'niveauPriorite_' . $i;
                $params[$key] = $v;
                $placeholders[] = ':' . $key;
            }
            $inExpr = implode(', ', $placeholders);
            $sql .= " AND (a.niveau_priorite IN ({$inExpr}) OR a.niveau_priorite_surcharge IN ({$inExpr}))";
        }

        if ($dto->typeAlerte !== null) {
            $sql .= ' AND a.type_alerte = :typeAlerte';
            $params['typeAlerte'] = $dto->typeAlerte;
        }

        // ── Score range ────────────────────────────────────────────────────────
        if ($dto->scoreMin !== null) {
            $sql .= ' AND COALESCE(a.score_surcharge, a.score_gei) >= :scoreMin';
            $params['scoreMin'] = (int) $dto->scoreMin;
        }
        if ($dto->scoreMax !== null) {
            $sql .= ' AND COALESCE(a.score_surcharge, a.score_gei) <= :scoreMax';
            $params['scoreMax'] = (int) $dto->scoreMax;
        }

        // ── Origine ────────────────────────────────────────────────────────────
        if (!empty($dto->origine)) {
            $sql .= ' AND a.origine = :origine';
            $params['origine'] = $dto->origine;
        }

        // ── Agent spécifique ───────────────────────────────────────────────────
        if (!empty($dto->agentId)) {
            $sql .= ' AND a.emetteur_id = :agentId';
            $params['agentId'] = (int) $dto->agentId;
        }

        // ── Manager spécifique ─────────────────────────────────────────────────
        if (!empty($dto->managerId)) {
            $sql .= ' AND a.validated_by_id = :managerId';
            $params['managerId'] = (int) $dto->managerId;
        }

        // ── Opérateur / Acteur — champ direct (+ fallback table alert_actor si peuplée) ──
        if ($dto->operateur !== '') {
            $sql .= ' AND (LOWER(a.operateur_acteur) LIKE :operateur'
                  . ' OR EXISTS (SELECT 1 FROM alert_actor ac WHERE ac.alert_id = a.id AND LOWER(ac.nom_ou_raison_sociale) LIKE :operateur))';
            $params['operateur'] = '%' . strtolower($dto->operateur) . '%';
        }

        // ── Marque — texte libre (recherche dans les champs texte riches) ──────
        if ($dto->marque !== '') {
            $sql .= ' AND (LOWER(a.marque) LIKE :marque OR LOWER(a.resume_executif) LIKE :marque OR LOWER(a.elements_factuels) LIKE :marque)';
            $params['marque'] = '%' . strtolower($dto->marque) . '%';
        }

        // ── Texte libre cross-champs ───────────────────────────────────────────
        if ($dto->texteLibre !== '') {
            $sql .= ' AND (LOWER(a.code_gei) LIKE :texteLibre OR LOWER(a.resume_executif) LIKE :texteLibre OR LOWER(a.port_corridor) LIKE :texteLibre OR LOWER(m.nom) LIKE :texteLibre OR LOWER(a.operateur_acteur) LIKE :texteLibre)';
            $params['texteLibre'] = '%' . strtolower($dto->texteLibre) . '%';
        }

        return [$sql, $params, $types];
    }

    /**
     * Construit une clause IN() avec des paramètres nommés expansés.
     * Évite la conversion "Array" par PDO qui empêchait tout résultat.
     *
     * @param bool $withAnd  Si true, préfixe la clause avec " AND ". Si false, retourne juste la condition.
     */
    private function buildInClause(
        string $column,
        string $prefix,
        array $values,
        array &$params,
        bool $withAnd = true
    ): string {
        if (empty($values)) {
            return '';
        }
        $placeholders = [];
        foreach ($values as $i => $v) {
            $key = $prefix . '_' . $i;
            $params[$key] = $v;
            $placeholders[] = ':' . $key;
        }
        $inExpr = "{$column} IN (" . implode(', ', $placeholders) . ')';
        return $withAnd ? " AND {$inExpr}" : $inExpr;
    }

    /**
     * Supprime tous les paramètres dont la clé commence par $prefix.
     */
    private function clearParamsWithPrefix(string $prefix, array &$params): void
    {
        foreach (array_keys($params) as $key) {
            if (str_starts_with($key, $prefix)) {
                unset($params[$key]);
            }
        }
    }
}
