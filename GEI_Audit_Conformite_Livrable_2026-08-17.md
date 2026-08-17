# LIVRABLE FINAL — AUDIT DE CONFORMITÉ GEI
## Date : 17 août 2026 | Référence : GEI_Prompt_Expert_Conformite_Originale.md

---

## SOMMAIRE EXÉCUTIF

Cet audit de conformité, réalisé selon le **Prompt Expert Vérification de Conformité aux Documents Originaux** (`GEI_Prompt_Expert_Conformite_Originale.md`), a passé en revue l'ensemble de la plateforme GEI Symfony et corrigé les non-conformités détectées par rapport aux trois documents de référence :

- **`ANNEXE_A___Fiche_D_alerte_Standard_GEI.docx`** — fiche complète en 8 sections
- **`ANNEXE_C___Matrice_De_Qualification_GEI.docx`** — logique de scoring officielle
- **Registre Excel `Annexe B`** — structure de données réelle en production

**Résultat global** : 7 non-conformités critiques corrigées, 27 tests automatisés écrits, 2 migrations Doctrine créées.

---

## PARTIE A — RÉPARTITION DES CHAMPS (Agent / Manager)

### État avant audit
- `AlertType` (Agent) exposait `sensibilite` et `recommandation` (sections 7/8 Manager)
- `QualificationType` (Manager) exposait `fiabiliteSource` (section 3 Agent)
- Champ `historiqueSource` manquant dans l'entité `Alert`

### Corrections appliquées

| Fichier | Correction | Statut |
|---------|-----------|--------|
| `src/Entity/Alert.php` | Ajout propriété `historiqueSource` (section 3) | ✅ Corrigé |
| `src/Entity/Alert.php` | Annotations PHPDoc pour clarté des zones Agent/Manager | ✅ Corrigé |
| `src/Form/AlertType.php` | Refactoring complet : **sections 1-5 uniquement** (Agent) | ✅ Corrigé |
| `src/Form/QualificationType.php` | Refactoring complet : **sections 6-8 uniquement** (Manager) | ✅ Corrigé |
| `migrations/Version20260817120000.php` | Migration pour `historique_source` | ✅ Créé |

### Détail de la répartition (conforme à l'Annexe A)

**Agent (AlertType)** :
- Section 1 : `market`, `portCorridor`, `categorie`
- Section 2 : `resumeExecutif`
- Section 3 : `typeSource`, `typeAlerte`, `anonymisation`, `historiqueSource`, `fiabiliteSource`
- Section 4 : `elementsFactuels`, `referenceDocumentaire`
- Section 5 : `hypothesesAnalytiques` (éditable aussi par le Manager)

**Manager (QualificationType)** :
- Section 6 : `credibiliteContenu`, `urgence`, `impact`, `exploitabilite` (score calculé auto)
- Section 7 : `recommandation`, `actionsEnCours`, `responsableSuivi`, `commentaires`
- Section 8 : `sensibilite`, `transmission`

---

## PARTIE B — MOTEUR DE SCORING

### État avant audit
- `ScoreCalculatorService` : seuils codés en dur dans `NiveauPriorite::fromScore()`
- `EscalationRuleEngine` :lecture `rule_config` via `EntityManager::getRepository`

### Corrections appliquées

| Fichier | Correction | Statut |
|---------|-----------|--------|
| `src/Service/ScoreCalculatorService.php` | Injection `RuleConfigRepository`, lecture des seuils depuis DB | ✅ Corrigé |
| `src/Enum\NiveauPriorite.php` | Méthode `fromScore()` conserve le fallback défaut Annexe C | ✅ Corrigé |
| `src\Service\EscalationRuleEngine.php` | Injection `RuleConfigRepository`, `findAllAsMap()` | ✅ Corrigé |
| `src\Repository\RuleConfigRepository.php` | Nouveau repository avec `findAllAsMap()` et `getInt()` | ✅ Créé |
| `migrations/Version20260817120100.php` | Migration d'initialisation des seuils | ✅ Créé |

### Vérifications de conformité B.1 à B.6

| Check | État |
|-------|------|
| Fiabilité enum lettres A/B/C/D (pas numérique 1-4) | ✅ Conforme |
| Crédibilité inversée (1→score 4, 4→score 1) | ✅ Testé, conforme |
| Formule : `(Fiabilité × Crédibilité) + Urgence + Impact + Exploitabilité` | ✅ Testée |
| Seuils ≥18 Critique, 14-17 Élevé, 10-13 Modéré, ≤9 Faible | ✅ Depuis `rule_config` |
| Séparation stricte Faits / Hypothèses / Recommandations | ✅ 3 champs distincts en base |
| Protection des sources (pas d'identité en clair) | ✅ `anonymisation` ENUM |

### Règles d'escalade (B.4) — 7 règles testées

| # | Condition | Action | Test unitaire |
|---|-----------|--------|---------------|
| 1 | Score ≥ 18 | `Urgence72hCase` créé | ✅ |
| 2 | Urgence Immédiat + Impact Élevé | Flag `transmissionPrioritaire` | ✅ |
| 3 | Exploitabilité Actionnable + Crédibilité ≤ 2 | `tache_analyse_rapide` | ✅ |
| 4 | Multi-corridor détecté | `multi_corridor_comite_ait` | ✅ |
| 5 | Score 14-17 | `validation_saholty_requise` | ✅ |
| 6 | Fiabilité C/D + Impact Élevé | `demande_corroboration` | ✅ |
| 7 | Crédibilité 3 + Urgence élevée | Flag `surveillanceRenforcee` | ✅ |

---

## PARTIE C — GÉNÉRATION DE L'ID GEI

### État avant audit
- Format `ALT-{ISO3}-{ANNEE}-{SEQ}` — **NON CONFORME** (devrait être `GEI-`)
- `getNextSequence()` utilisait LIKE + REGEX — risque de concurrence

### Corrections appliquées

| Fichier | Correction | Statut |
|---------|-----------|--------|
| `src/Service/AlertCodeGeneratorService.php` | Préfixe `GEI-` (au lieu de `ALT-`) | ✅ Corrigé |
| `src/Service/AlertCodeGeneratorService.php` | `SELECT MAX` avec `FOR UPDATE` (DBAL native) | ✅ Corrigé |
| `src/Service/AlertCodeGeneratorService.php` | Table `paysToIso3()` pour correspondance pays→ISO3 | ✅ Ajouté |

### Tests C.2 — vérifiés et conformes

| Test | Attendu | Résultat |
|------|---------|----------|
| BEN-2026 dernier = 005 → nouveau | `GEI-BEN-2026-006` | ✅ |
| CIV-2026 aucun → premier | `GEI-CIV-2026-001` | ✅ |
| Passage année (BEN-2026=087 → BEN-2027) | `GEI-BEN-2027-001` | ✅ |
| Format 3 chiffres (001, 002...) | `GEI-{ISO3}-{YYYY}-{SEQ:003}` | ✅ |

---

## PARTIE D — CYCLE SOUMISSION → NOTIFICATION → VALIDATION

### État avant audit
- `AlertEscalationHandler` ne faisait qu'un log (`$logger->info`)
- Pas de notifications réelles en base de données

### Corrections appliquées

| Fichier | Correction | Statut |
|---------|-----------|--------|
| `src/MessageHandler/AlertEscalationHandler.php` | 7 types de notifications réels en base (`Notification`) | ✅ Corrigé |
| `src/Service/NotificationService.php` | Méthodes existantes préservées | ✅ Conforme |
| `src/Repository/UserRepository.php` | `findByRole()` et `findByRoleAndMarket()` ajoutés | ✅ Corrigé |

### Flux de notification implémenté

1. **Agent soumet** → Workflow statut → ID GEI généré (prePersist)
2. **Manager notifié** → `AlertEscalationMessage(type: 'nouvelle_alerte')` dispatché
3. **Qualification** → Score calculé auto → Règles d'escalade évaluées → Notifications
4. **Validation** → Statut change → Agent notifié du changement de statut

---

## PARTIE E — VUE REGISTRE & IMPORT EXCEL

### État avant audit
- `ExportService` : Score/Priorité en position 12/13 (pas en fin)
- `ImportService` : erreur PHP (`$this->em.persist` → `$this->em->persist`)
- `ImportService` : pas de validation de l'en-tête (23 colonnes)

### Corrections appliquées

| Fichier | Correction | Statut |
|---------|-----------|--------|
| `src/Service/ExportService.php` | 25 colonnes exactes (23 registre + Score/Priorité en fin) | ✅ Corrigé |
| `src/Service/ImportService.php` | Validation stricte en-tête 23 colonnes | ✅ Corrigé |
| `src/Service/ImportService.php` | Correction typo `em->persist` | ✅ Corrigé |
| `src/Service/ImportService.php` | Doublons → update au lieu de rejet | ✅ Corrigé |
| `src/Service/ImportService.php` | Score recalculé systématiquement (jamais importé) | ✅ Corrigé |

### Colonnes export (ordre exact Partie E.1)

```
A: ID GEI         B: Date            C: Émetteur        D: Zone (pays)
E: Port / Corr.   F: Catégorie       G: Résumé court    H: Type source
I: Anonymisation  J: Fiabilité (A-D) K: Crédibilité (1-4) L: Urgence
M: Impact         N: Exploitabilité O: Statut          P: Transmission
Q: Actions        R: Pièces          S: Réf. doc.       T: Sensibilité
U: Dernière MAJ   V: Responsable    W: Commentaires    X: Score GEI
Y: Niveau priorité
```

---

## TESTS AUTOMATISÉS ÉCRITS

| Fichier | Tests | Couvre |
|---------|-------|--------|
| `tests/Unit/ScoreCalculatorTest.php` | 8 | Maximum (25=C), Plancher (4=F), Crédibilité inversée, Seuils |
| `tests/Unit/AlertCodeGeneratorTest.php` | 7 | Format GEI-, Séquence, Passage année, Table pays→ISO3 |
| `tests/Unit/EscalationRuleEngineTest.php` | 13 | Règles 1-7 (chaque condition/action) |

**Total : 28 tests automatisés** — tous passent.

---

## POINTS À TRANCHER AVEC LE CLIENT

### 1. Génération de l'ID GEI (Partie D)

**Situation actuelle** : L'ID GEI est généré au `prePersist` (création du brouillon).

**Recommandation d'expert** : Générer à la **soumission** (quand l'Agent valide le formulaire), pour éviter les trous de numérotation sur les brouillons abandonnés.

**Impact du changement** : Déplacer la génération du `AlertScoreSubscriber::prePersist` vers `AlertController::new` après `form->isValid()` et avant `$em->persist()`.

**Décision attendue** : Confirmer si le modèle actuel (génération au persist) est accepté, ou basculer sur génération à la soumission.

---

### 2. Règle de validation minimale (Partie B.5)

**Document Annexe C** : *"Validation minimale : PFT + 2 marchés + SAHOLTY"*

**Situation actuelle** : Le modèle utilise 2 rôles (Agent/Manager). Le Manager (PFT) cumule les fonctions.

**Question** : Cette règle s'applique-t-elle dans le modèle simplifié, ou est-elle **volontairement simplifiée** parce que le Manager (PFT) cumule ces fonctions ?

**Décision attendue** : Confirmer l'interprétation ou préciser les rôles impliqués.

---

### 3. Colonne "Référence documentaire" (Partie C.3)

**Document** : Le registre a une colonne `Référence documentaire` (ex. `ALT-BJA02-2026-001`).

**Situation actuelle** : Champ texte libre optionnel dans `AlertType`.

**Question** : Faut-il implémenter une logique de génération automatique pour ce champ (avec sous-code agence ex. "BJA02"), ou le laisser en texte libre ?

**Décision attendue** : Confirmer le traitement actuel (texte libre) ou définir le format attendu.

---

### 4. Colonnes Score GEI et Niveau priorité dans l'export (Partie E.1 note)

**Situation actuelle** : Ajoutées en colonnes X/Y (24/25) de l'export.

**Question** : Le client souhaite-t-il ces colonnes dans l'export (valeur ajoutée outil) ou les garder uniquement en lecture interface ?

**Décision attendue** : Confirmation d'inclusion dans l'export (actuellement incluses).

---

## FICHIERS MODIFIÉS / CRÉÉS

### Entités et Enums
- `src/Entity/Alert.php` — + `historiqueSource`, annotations clarté Agent/Manager
- `src/Entity/RuleConfig.php` — + `repositoryClass`

### Formulaires
- `src/Form/AlertType.php` — Refactoring sections 1-5 (Agent only)
- `src/Form/QualificationType.php` — Refactoring sections 6-8 (Manager only)

### Services
- `src/Service/ScoreCalculatorService.php` — Lecture seuils depuis `rule_config`
- `src/Service/AlertCodeGeneratorService.php` — Format `GEI-`, DBAL transactionnel
- `src/Service/EscalationRuleEngine.php` — Injection `RuleConfigRepository`
- `src/Service/ImportService.php` — Validation en-tête, correction typo, doublons→update
- `src/Service/ExportService.php` — 25 colonnes ordre exact

### Message Handlers
- `src/MessageHandler/AlertEscalationHandler.php` — 7 types notifications réels

### Repositories
- `src/Repository/RuleConfigRepository.php` — Nouveau avec `findAllAsMap()`, `getInt()`
- `src/Repository/UserRepository.php` — `findByRole()`, `findByRoleAndMarket()`

### DataFixtures
- `src/DataFixtures/AppFixtures.php` — Injection `RuleConfigRepository`, ordre flush

### Migrations
- `migrations/Version20260817120000.php` — + colonne `historique_source`
- `migrations/Version20260817120100.php` — + seuils `rule_config`

### Tests
- `tests/Unit/ScoreCalculatorTest.php` — 8 tests
- `tests/Unit/AlertCodeGeneratorTest.php` — 7 tests
- `tests/Unit/EscalationRuleEngineTest.php` — 13 tests

---

## COMMANDE DE DÉPLOIEMENT

```bash
# 1. Appliquer les migrations
php bin/console doctrine:migrations:migrate -n

# 2. Recharger les fixtures (optionnel — pour tester avec données réalistes)
php bin/console doctrine:fixtures:load -n

# 3. Lancer les tests
php bin/phpunit

# 4. Vérifier les logs d'escalade
tail -f var/log/dev.log | grep "GEI Escalade"
```

---

## SIGNATURE

**Audit réalisé** : 17 août 2026
**Référence** : `GEI_Prompt_Expert_Conformite_Originale.md`
**Statut** : ✅ Terminé — 4 points à trancher avec le client (sections 1-4 ci-dessus)

---

*Ce document constitue le livrable final de l'audit de conformité. Toute modification des points signalés comme "À trancher" devra être validée par le client avant mise en production.*