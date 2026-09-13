# Documentation Fonctionnelle — Plateforme GEI

> **Source unique de vérité** : ce document reflète l'état réel du code (`src/`, `migrations/`, `config/`) au 12/09/2026.  
> Les éléments marqués **[ÉCART / À VENIR]** ne sont pas livrés ou leur position officielle n'est pas confirmée.

---

## 1. Rôles & Portées (UserRoleEnum)

| Rôle (Enum) | Rôle Symfony | Description | Portée alertes |
|-------------|--------------|-------------|----------------|
| `SUPERADMIN` | `ROLE_SUPERADMIN` + héritage complet | Gestion totale utilisateurs, rôles, config système, bascule utilisateur | Toutes |
| `SECRETARIAT_GEI` | `ROLE_SECRETARIAT_GEI` | Enregistrement / mise à jour registre, **pas de pouvoir de décision** | Vue globale + export |
| `SAHOLTY` | `ROLE_SAHOLTY` (hérite `ROLE_PFT`) | Analyse régionale, **validation transmissions externes**, vue multi-pays | Toutes (qualification + décision) |
| `PFT` (Point Focal Technique) | `ROLE_PFT` (hérite `ROLE_EMETTEUR_TERRAIN`) | **Qualification, validation, coordination** au niveau marché. Droit d'édition **total et permanent** sur alertes de ses marchés (Spec A.1) | Marchés gérés (`manager_marches` + `market` legacy) |
| `EMETTEUR_TERRAIN` (Agent) | `ROLE_EMETTEUR_TERRAIN` | Création alertes via Wizard 6 étapes, vision limitée à **ses propres alertes** | Propres alertes uniquement |
| `COMITE_AIT` | `ROLE_COMITE_AIT` | Vue KPI/stratégique, escalades critiques uniquement | Vue globale + export |

> **Règle de création Agent** : un Agent ne peut **jamais** s'auto-inscrire. Le compte est créé par un Manager (PFT) via `User.createdByManager` (Partie C).

---

## 2. Cycle de vie d'une alerte (Workflow Symfony)

**Machine à états** (`config/packages/workflow.yaml`) — 12 places, transitions explicites :

```
nouveau → en_cours ↔ a_completer ↔ a_investiguer → a_valider_saholty → validee
                                                              ↓ rejeter
                                                            a_completer
validee → transmis → suivi → clos
         ↘ archiver (depuis n'importe quel état actif)
archive / clos → rouvrir → en_cours
```

- **Statuts ajoutés post-audit** (17/08/2026) : `ouvert`, `en_cours_analyse`, `ouvert_prioritaire` — présents dans l'enum `AlertStatut` mais **non câblés dans le workflow** actuel.
- **Garde-fou** : le statut est piloté **uniquement** par le workflow (MethodMarkingStore sur `Alert::statut`). Aucune modification directe autorisée hors transitions.

### Étapes métier clés

| Étape | Acteur | Action | Résultat |
|-------|--------|--------|----------|
| 1. Saisie | Agent (Wizard 6 étapes) | Remplit 20 champs officiels (Annexe A) | `statut = nouveau` → `a_valider_saholty` à la soumission |
| 2. Qualification | Manager (PFT) | Vérifie/corrige critères scoring, **valide** ou **rejette** (commentaire obligatoire) | `validee` OU `a_completer` |
| 3. Transmission | Manager (PFT) | Enregistre destinataire externe (Douanes, Police, etc.) | `transmission = OUI`, `statut = transmis` |
| 4. Suivi/Clôture | Manager (PFT) | Marque transmission `cloture` | `statut = cloture` |

---

## 3. Score GEI & Niveau de Priorité (Annexe C)

### Formule (ScoreCalculatorService)

```
Score GEI = (Fiabilité × Crédibilité) + Urgence + Impact + Exploitabilité
```

| Critère | Valeurs | Conversion en points |
|---------|---------|---------------------|
| **Fiabilité** (A–D) | A, B, C, D | A=4, B=3, C=2, D=1 |
| **Crédibilité** (1–4) | 1 (plus crédible) → 4 (non vérifiable) | **Inversée** : 1→4, 2→3, 3→2, 4→1 |
| **Urgence** | Immédiat, 72h, Routine | 3, 2, 1 |
| **Impact** | Élevé, Moyen, Faible | 3, 2, 1 |
| **Exploitabilité** | Actionnable, À analyser, À surveiller, À compléter, Archivage | 3, 2, 2, 1, 1 |

> **Test B.2 obligatoire** : Crédibilité=1 contribue **4 points** (pas 1).

### Seuils de priorité (configurables via `rule_config` — table éditable Superadmin sans redéploiement)

| Niveau | Seuil (défaut Annexe C) | Clé `rule_config` |
|--------|------------------------|-------------------|
| CRITIQUE | ≥ 18 | `score_critique` |
| ÉLEVÉ | ≥ 14 | `score_eleve_min` |
| MODÉRÉ | ≥ 10 | `score_modere_min` |
| FAIBLE | < 10 | — |

- **Surcharge manuelle Manager** : `scoreSurcharge` + `niveauPrioriteSurcharge` + `justificationSurcharge` (traçabilité `surchargePar`). Le score effectif = surcharge si présente, sinon calculé.

### Historique immuable
Chaque recalcul crée une entrée `AlertQualificationHistory` (snapshot complet : critères, scores, seuils appliqués, `calculePar`). **Jamais écrasée**.

---

## 4. Urgence 72h (Partie F)

### Déclenchement (EscalationRuleEngine — Règle 1)
- **Automatique** : `score ≥ 18` **ET** `urgence = 72h` **ET** `statut = VALIDEE` → création `Urgence72hCase` (T0 immuable).
- **Manuelle** : PFT peut activer avec justification (`activationManuelle = true`, `justificationManuelle`).

### 3 Phases (PhaseUrgence) — SLA durs

| Phase | Ordre | SLA (heures) | Description |
|-------|-------|--------------|-------------|
| `DETECTION` | 0 | T+0 | Auto-complétée à la création |
| `COORDINATION` | 1 | T+6 | Début auto à T0 |
| `TRANSMISSION` | 2 | T+24 | — |
| `VALIDATION` | 3 | T+48 | — |
| `CLOTURE` | 4 | T+72 | — |

> **Couleurs SLA dashboard** : vert (>24h), orange (6–24h), rouge (<6h).

### Surveillance SLA (Commande cron `app:sla:watch`)
- Exécution recommandée : `*/15 * * * *`
- Notification **1h avant échéance** (type `sla_proche`)
- Notification **SLA dépassé** (type `urgence`)

---

## 5. Transmissions Externes (Annexe A §7 + Annexe D §6)

### Entité `AlertTransmission`
- **Double validation requise** si score 14–17 : PFT **ET** SAHOLTY (`validePftBy`, `valideSaholtyBy` + horodatages).
- Statuts : `en_cours` (défaut), `cloture` (action Manager).
- Traçabilité : `transmisBy` (Manager), `transmisLe`, `destinataire`, `noteTransmission`.

### Règle 5 (EscalationRuleEngine)
- Score 14–17 → statut passe en `a_valider_saholty` **avant** transmission (blocage workflow).

---

## 6. Audit & Traçabilité

### Journaux d'accès (`AccessLog`)
- Actions : `CREATION`, `MODIFICATION`, `EXPORT`, `TRANSMISSION`, `CONNEXION`, `DECONNEXION`, `QUALIFICATION`, `VALIDATION`, `REJET`, `SURCHARGE`.
- Diff avant/après sur modification (`diffData` JSON).
- IP, user-agent, résultat (success/failed).

### Historique de statut (`AlertStatusHistory`)
- `ancienStatut`, `nouveauStatut`, `changedBy`, `transitionName`, `justification`.
- Ordre chronologique DESC.

### Commentaires historisés (`AlertComment`)
- `auteur`, `contenu`, `type` (`agent`/`manager`/`system`), `createdAt`.

### Module Audit (AuditController + AuditService)
- Filtres : pays, statut, priorité, agent, manager, période, score min/max, origine, urgence 72h.
- **Portée selon rôle** : Agent = propres alertes ; Manager = ses marchés ; SAHOLTY/SUPERADMIN = toutes.
- Agrégations : total, score moyen, critiques, taux transmission, par pays/catégorie/priorité/année/statut/agent/urgence/exploitabilité.
- Exports : Excel (ExportService), PDF (PdfExportService), Statistiques (ExportStatsService).

---

## 7. Import Registre Excel (ImportService)

### Détection dynamique des colonnes
- **Pas de position fixe** : recherche par fragments d'intitulés (`COLUMN_MAP`).
- Compatible avec fichier réel où **colonne A est vide**.

### Mapping Enum avec alias (registre réel)
- Urgence : `"72 h"` → `72h`, `"immédiat"` → `immediat`
- Impact : `"très élevé"` → `eleve`, `"moyen à élevé"` → `moyen`
- Exploitabilité : `"à analyser"` → `a_analyser`, `"actionnable"` → `actionnable`
- Statut : `"en cours d'analyse"` → `en_cours_analyse`, `"ouvert – prioritaire"` → `ouvert_prioritaire`

### Rapport d'import
- `success_count`, `update_count`, `error_count`, `errors[]`
- **`lignes_incompletes[]`** : `{line, id, champs_manquants[], alert_id}` — pour suivi Manager.

### Traçabilité import
- `Alert.origine = 'import_excel'`
- `Alert.importBatchId` (UUID) — permet annulation par lot.
- Préservation `emetteurTexte` original (ex. "Point focal Douanes Bénin") si User non trouvé.

---

## 8. Export Registre (ExportService)

### Colonnes générées (ordre exact registre source + ajouts plateforme)

| Col | Intitulé | Source |
|-----|----------|--------|
| A | ID GEI | `Alert.codeGei` |
| B | Date | `Alert.dateCreation` |
| C | Émetteur (fonction/pays) | `User.nomComplet` |
| D | Zone (pays) | `Market.nom` |
| E | Port / Corridor | `Alert.portCorridor` |
| F | Catégorie | `Alert.categorie` |
| G | Résumé court | `Alert.resumeExecutif` |
| H | Type de source | `Alert.typeSource` |
| I | Anonymisation | `Alert.anonymisation` |
| J | Fiabilité (A–D) | `Alert.fiabiliteSource->value` |
| K | Crédibilité (1–4) | `Alert.credibiliteContenu` |
| L | Urgence | `Alert.urgence->label()` |
| M | Impact | `Alert.impact->label()` |
| N | Exploitabilité | `Alert.exploitabilite->label()` |
| O | Statut | `Alert.statut->label()` |
| P | Transmission | `Alert.transmission->label()` |
| Q | Actions en cours | `Alert.actionsEnCours` |
| R | Pièces (Oui/Non + type) | `Alert.piecesDisponibles` + `piecesType` |
| S | Référence documentaire | `Alert.referenceDocumentaire` |
| T | Sensibilité | `Alert.sensibilite->label()` |
| U | Dernière MAJ | `Alert.updatedAt` |
| V | Responsable suivi | `Alert.responsableSuivi->nomComplet` |
| W | Commentaires | `Alert.commentaires` |
| **X** | **Score GEI** | **Ajout plateforme** (`effectiveScore`) |
| **Y** | **Niveau de priorité** | **Ajout plateforme** (`effectiveNiveauPriorite->label()`) |
| **Z** | **Agent soumetteur** | **Partie B** (traçabilité nominative) |
| **AA** | **Manager validateur** | **Partie B** (`validatedBy->nomComplet`) |
| **AB** | **Date de validation** | **Partie D** (`dateValidation`) |
| **AC** | **Délai traitement (h)** | **Partie D** (`getDelaiTraitementHeures()`) |
| **AD** | **Opérateur / Acteur** | **Ajout plateforme** (`Alert.operateurActeur`, positionnée en colonne additionnelle AD à la fin du fichier pour préserver l'ordre des 23 colonnes officielles) |

> **Position confirmée colonne AD (`operateur_acteur`)** : La colonne est ajoutée en colonne supplémentaire AD (30e colonne) à la fin du fichier Excel, sans décaler les 23 colonnes officielles de l'Annexe A.

---

## 9. Notifications & Escalades (7 règles — EscalationRuleEngine)

| Règle | Condition | Action |
|-------|-----------|--------|
| 1 | Score ≥ 18 + urgence 72h + validée | Crée `Urgence72hCase` + notifie PFT/SAHOLTY + email critique |
| 2 | Urgence = Immédiat **ET** Impact = Élevé | `transmissionPrioritaire = true` + notifie PFT/SAHOLTY |
| 3 | Exploitabilité = Actionnable **ET** Crédibilité ≤ 2 | Tâche "analyse rapide" PFT (échéance +6h) |
| 4 | Corridor multi-pays (heuristique `/`, `–`, `,`) | Notifie COMITE_AIT + SAHOLTY |
| 5 | Score 14–17 | Blocage `a_valider_saholty` + notifie SAHOLTY + Manager |
| 6 | Fiabilité C/D **ET** Impact = Élevé | Tâche "demande corroboration" Manager |
| 7 | Crédibilité = 3 **ET** Urgence ∈ {Immédiat, 72h} | `surveillanceRenforcee = true` + notifie Manager |

Toutes les notifications sont persistées (`Notification` entity) + log `AccessLog`.

---

## 10. Fonctionnalités **NON LIVRÉES / PARTIELLES** (Écarts identifiés)

| Fonctionnalité | Statut | Détail |
|----------------|--------|--------|
| Workflow statuts `ouvert`, `en_cours_analyse`, `ouvert_prioritaire` | **Enum présente, workflow absent** | Transitions non définies dans `workflow.yaml` |
| Position officielle champ `operateur_acteur` (colonne AD export) | **LIVRÉ & CONFIRMÉ** | Migration `Version20260912160000` appliquée, positionnée en colonne additionnelle AD |
| Suivi des transmissions (actions directes, réouverture, repli interactif) | **LIVRÉ & CONFIRMÉ** | Actions Clôturer et Rouvrir directes, toggle note 120 car., filtre dynamique Stimulus |
| Audit multicritère (moteur ET strict, monotonie, 3 vues synchronisées) | **LIVRÉ & VALIDÉ** | Requête unique partagée (texte, graphique, tableau), années 4 chiffres, autocomplétion opérateur |
| Notifications temps réel (WebSocket/Mercure) | **Non implémenté** | Seulement polling / rechargement page |
| API REST complète (API Platform installé mais ressources vides) | **Squelette seulement** | `src/ApiResource/` vide |
| Gestion pièces jointes (upload, preview, signature) | **Entités prêtes** (`AlertAttachment`, `AlertActor`) mais **pas d'UI/contrôleur** |
| Tableau de bord cartographique (CarteController) | **LIVRÉ & VALIDÉ** | Filtre Marché en 1ère position, badge filtré, masquage marchés 0 alerte, double dimension volume/priorité, tuiles libres OSM/CARTO |
| Authentification SSO / LDAP | **Non implémenté** | Seule auth locale + JWT + TOTP |
| Plan de reprise d'activité (PRA) / Backup automatisé | **Non documenté** | Dépend de l'infra cible |
| Tests E2E (Cypress/Playwright) | **Absents** | Seulement PHPUnit unit/integration |

---

## 11. Références croisées code

| Concept | Fichier principal |
|---------|-------------------|
| Entité centrale | `src/Entity/Alert.php` |
| Rôles & hiérarchie | `src/Enum/UserRoleEnum.php` |
| Workflow | `config/packages/workflow.yaml` |
| Score & seuils | `src/Service/ScoreCalculatorService.php` |
| Règles escalade | `src/Service/EscalationRuleEngine.php` |
| Import Excel | `src/Service/ImportService.php` |
| Export Excel | `src/Service/ExportService.php` |
| Votants (autorisations) | `src/Voter/AlertVoter.php`, `src/Voter/UserVoter.php` |
| Urgence 72h | `src/Entity/Urgence72hCase.php`, `src/Entity/Urgence72hPhase.php` |
| Transmissions | `src/Entity/AlertTransmission.php` |
| Commande SLA | `src/Command/SlaWatcherCommand.php` |
| Subscriber score auto | `src/EventSubscriber/AlertScoreSubscriber.php` |