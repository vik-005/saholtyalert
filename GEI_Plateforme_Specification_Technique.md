# SPÉCIFICATION TECHNIQUE — PLATEFORME GEI (Groupe d'Échange d'Informations)
### Cahier des charges fonctionnel & technique pour développement Symfony
**Version 1.0 — Rédigé à partir de l'analyse du registre GEI-Bénin, des Annexes A, C, D, E**
**Confidentialité : document de conception — à traiter selon le niveau de sensibilité du dispositif GEI/AIT**

---

## 0. SYNTHÈSE DE L'ANALYSE DOCUMENTAIRE

J'ai analysé minutieusement les 5 fichiers fournis :

| Fichier | Nature | Ce qu'il révèle pour la conception |
|---|---|---|
| **Registre GEI (xlsx)** | Export consolidé de ~90 alertes réelles (Bénin, Togo, Ghana, Côte d'Ivoire, Sénégal, Niger, Guinée, Angola, Cameroun, RDC) | **C'est la vue "liste" finale du système** → devient la table pivot `alert` + une vue `registre` exportable. 23 colonnes identifiées, dont certaines redondantes/à normaliser (ex. colonnes vides en fin de ligne = artefacts Excel à ignorer). |
| **Annexe A — Fiche d'alerte standard** | Formulaire de saisie terrain (8 sections) | Devient le **formulaire de création/édition d'une alerte**, avec cases à cocher → `enum`/`choice` Symfony Form. |
| **Annexe C — Matrice de qualification** | Règles de scoring et d'escalade | Devient le **moteur de règles métier automatique** (calcul de score, classification, escalade). |
| **Annexe D — Charte de confidentialité** | Règles d'accès, RBAC, cycle de vie des données | Devient le **modèle de rôles/permissions + politique de rétention/traçabilité**. |
| **Annexe E — Procédure Urgence 72h** | Workflow SLA en 5 phases (T0 → T+72h) | Devient le **workflow d'état (state machine) avec minuteurs SLA automatiques**. |

**Conclusion d'architecture** : il ne s'agit pas de 5 documents séparés mais d'**un seul système d'information de renseignement opérationnel** (alertes → qualification → workflow d'urgence → registre → KPI), avec un moteur de règles et un contrôle d'accès strict comme colonne vertébrale. C'est ainsi que la plateforme doit être pensée.

---

## 1. ARCHITECTURE TECHNIQUE RECOMMANDÉE

```
Backend  : Symfony 7.x + API Platform (REST/JSON:API) + Doctrine ORM
Auth     : Symfony Security + JWT (LexikJWTAuthenticationBundle) + 2FA (scheb/2fa) — obligatoire vu la sensibilité
Base     : PostgreSQL 15+ (JSONB pour champs semi-structurés type "acteurs impliqués", full-text search natif)
Workflow : Symfony Workflow Component (state machine officielle) pour le cycle de vie alerte + urgence 72h
Async    : Symfony Messenger + RabbitMQ/Redis (notifications, escalades, calcul de score en tâche de fond)
Front    : Twig + Stimulus/Turbo (ou Next.js découplé si vous voulez réutiliser votre stack habituelle)
Export   : PhpSpreadsheet (génération du registre .xlsx natif, identique au fichier source)
Audit    : DoctrineExtensions Loggable + table d'audit dédiée (voir §4.7)
PDF      : Dompdf/mPDF pour génération Fiche d'Alerte imprimable et rapports KPI
Chiffrement : Champs sensibles (identité source) chiffrés au repos (ex. bundle `ambta/doctrine-encrypted-value`)
```

**Pourquoi ce choix** : le dispositif décrit dans l'Annexe D exige traçabilité, cloisonnement et canaux sécurisés — Symfony Security + Workflow + audit natif couvrent ces 3 exigences sans réinventer la roue, contrairement à une stack plus légère.

---

## 2. MODÈLE DE DONNÉES (BASE DE DONNÉES)

### 2.1 Schéma entités-relations (vue d'ensemble)

```
User ──< UserRole >── Role ──< RolePermission >── Permission
  │
  ├──< AccessLog (traçabilité Annexe D)
  │
Market (pays/marché) ──< FocalPoint (PFT)
  │
Alert (Fiche GEI) ──> Market
  │  ├──< AlertActor (acteurs impliqués)
  │  ├──< AlertAttachment (pièces jointes)
  │  ├──< AlertQualification (score, historique de recalcul)
  │  ├──< AlertStatusHistory (audit des changements de statut)
  │  ├──< AlertTransmission (destinataires, validation, date)
  │  └──< Urgence72hCase (si escaladée) ──< Urgence72hPhase (5 phases horodatées)
  │
IncidentSecurity (violations, Annexe D §10)
Source (métadonnées anonymisées : fiabilité, type, historique — jamais l'identité en clair)
```

### 2.2 Détail des tables principales

#### `alert` (cœur du système — issu du registre + Annexe A)

| Champ | Type | Origine | Règle |
|---|---|---|---|
| `id` | UUID (PK) | — | généré |
| `code_gei` | string(30), unique | `ID GEI` (ex. GEI-BEN-2026-001) | **généré automatiquement** : `GEI-{ISO3_PAYS}-{ANNEE}-{SEQ}` |
| `date_creation` | date | `Date` | auto (T0) |
| `emetteur_id` | FK → User | `Émetteur (fonction/pays)` | — |
| `market_id` | FK → Market | `Zone (pays)` | liste fermée (Bénin, Togo, Ghana, CI, Mali, Niger, Sénégal, Guinée, Angola, Cameroun, RDC, Autre) |
| `port_corridor` | string(150) | `Port / Corridor` | autocomplete sur valeurs déjà saisies |
| `categorie` | enum | `Catégorie` | `container_suspect, flux_terrestre, acteur, modus_operandi, transit_tabac, saisie, veille_marche, autre` |
| `resume_executif` | text (max 5 lignes UI) | Annexe A §2 | limite 500 car., compteur live |
| `type_source` | enum | `Type de source` | `terrain, institution, open_source, manifeste_portuaire, declaration_douaniere, autre` |
| `anonymisation` | enum | `Anonymisation` | `eleve, partiel, faible` — **`eleve` par défaut, non désactivable sans rôle SAHOLTY** |
| `fiabilite_source` | enum(A,B,C,D) | `Fiabilité` | cf. §3.1 |
| `credibilite_contenu` | int(1-4) | `Crédibilité` | cf. §3.1 |
| `urgence` | enum | `Urgence` | `immediat, 72h, routine` |
| `impact` | enum | `Impact` | `eleve, moyen, faible` |
| `exploitabilite` | enum | `Exploitabilité` | `actionnable, a_completer, archivage` |
| `statut` | enum (workflow) | `Statut` | `nouveau, en_cours, a_investiguer, suivi, ouvert, clos` — piloté par Symfony Workflow (§4) |
| `transmission` | enum | `Transmission` | `oui, non, a_valider` |
| `actions_en_cours` | text | `Actions en cours` | — |
| `pieces_disponibles` | boolean + `piece_type` enum multiple | `Pièces` | checkboxes Annexe A §4 |
| `reference_documentaire` | string(100) | `Référence documentaire` | ex. ALT-BJA02-2026-001 |
| `sensibilite` | enum | `Sensibilité` | `interne, restreinte, confidentielle` |
| `derniere_maj` | datetime | `Dernière MAJ` | auto (trigger update) |
| `responsable_suivi_id` | FK → User | `Responsable suivi` | — |
| `commentaires` | text | `Commentaires` | — |
| `elements_factuels` | text | Annexe A §4 | séparé des hypothèses (garde-fou Annexe C) |
| `hypotheses_analytiques` | text | Annexe A §5 | **jamais fusionné avec les faits en base ni à l'affichage** |
| `recommandation` | enum | Annexe A §7 | `surveiller, approfondir, transmission, urgence_operationnelle` |
| `score_gei` | int (calculé) | Annexe C | recalculé automatiquement à chaque modification (voir §3) |
| `niveau_priorite` | enum (calculé) | Annexe C | `critique, eleve, modere, faible` |
| `created_at` / `updated_at` | datetime | — | — |

#### `alert_actor`
`id, alert_id (FK), nom_ou_raison_sociale, role (expéditeur/destinataire/transporteur/autre), pays, statut_verification`

#### `alert_attachment`
`id, alert_id (FK), type (document/photo/tracking/temoignage), fichier_chemin (stockage chiffré), uploaded_by, uploaded_at`

#### `alert_qualification_history`
`id, alert_id (FK), score_gei, niveau_priorite, calcule_par (system/user_id), calcule_le` — **garde tout l'historique**, jamais d'écrasement, pour audit du raisonnement.

#### `urgence_72h_case`
`id, alert_id (FK unique), date_activation (T0), pft_responsable_id, saholty_valide_par_id, statut_case (active/cloturee), date_cloture, delai_reel_heures`

#### `urgence_72h_phase`
`id, case_id (FK), phase (detection/qualification/validation/coordination/suivi), sla_heure_limite (T0+6h / +24h / +48h / +72h), date_debut, date_fin, responsable_id, en_retard (bool, calculé), commentaire`

#### `source` (métadonnées uniquement, jamais l'identité brute — Annexe D)
`id, code_anonyme, fiabilite (A-D), historique (connue_fiable/moyenne/non_verifiee), pays, cree_le` — **le champ identité réel, s'il doit exister, est dans une table séparée `source_identity` chiffrée AES-256, accessible uniquement au rôle `SAHOLTY_ADMIN` avec journalisation systématique de chaque lecture.**

#### `incident_security` (Annexe D §10)
`id, alert_id (FK nullable), type_incident, gravite, description, signale_par_id, signale_le, mesures_correctives, statut, escalade_comite_ait (bool)`

#### `access_log` (traçabilité obligatoire Annexe D §6/§8)
`id, user_id, alert_id (nullable), action (lecture/modification/export/transmission), date_action, ip_adresse, resultat`

#### `user` / `role` / `permission`
Rôles fixes issus de la Charte (Annexe D §2 et Annexe E §4) :
- `EMETTEUR_TERRAIN` (marché/consultant) — crée une alerte, ne voit que les siennes + celles de son pays
- `PFT` (Point Focal Technique) — qualifie, valide, coordonne son marché
- `SAHOLTY` — analyse régionale, valide transmissions externes, vue multi-pays
- `SECRETARIAT_GEI` — enregistrement, mise à jour registre, pas de pouvoir de décision
- `COMITE_AIT` — vue KPI/stratégique, escalades critiques uniquement
- `SUPERADMIN` — gestion utilisateurs, rôles, accès, aucune restriction

---

## 3. RÈGLES DE GESTION AUTOMATIQUES (MOTEUR DE RÈGLES)

### 3.1 Calcul automatique du score (Annexe C — à coder en service Symfony `ScoreCalculatorService`)

**Table de conversion (constantes applicatives, jamais en dur dans les formulaires) :**

| Critère | Conversion |
|---|---|
| Fiabilité | A=4, B=3, C=2, D=1 |
| Crédibilité | 1→4, 2→3, 3→2, 4→1 |
| Urgence | Immédiat=3, 72h=2, Routine=1 |
| Impact | Élevé=3, Moyen=2, Faible=1 |
| Exploitabilité | Actionnable=3, À compléter=2, Archivage=1 |

**Formule (déclenchée `onFlush` Doctrine à chaque création/modification) :**
```
Score GEI = (Fiabilité × Crédibilité) + Urgence + Impact + Exploitabilité
```

**Interprétation automatique du score → `niveau_priorite` :**
| Score | Niveau | Couleur UI |
|---|---|---|
| ≥ 18 | Critique | 🔴 rouge |
| 14–17 | Élevé | 🔴/🟠 |
| 10–13 | Modéré | 🟠 |
| ≤ 9 | Faible | 🟡 |

### 3.2 Règles d'escalade automatique (à coder en `EscalationRuleEngine`, exécuté après chaque calcul de score)

| Condition (moteur de règles, évaluée par ordre) | Action système déclenchée automatiquement |
|---|---|
| Score ≥ 18 | Création automatique d'un `Urgence72hCase` + notification immédiate PFT + SAHOLTY (email/push) |
| Urgence = Immédiat **ET** Impact = Élevé | Flag `transmission_prioritaire = true` + notification SAHOLTY |
| Exploitabilité = Actionnable **ET** Crédibilité ≥ 2 (soit code 2 ou 1) | Tâche "analyse rapide" assignée au PFT, échéance +6h |
| Alerte liée à ≥ 2 pays/corridors (détection auto via `market_id` multiple ou texte du corridor) | Notification automatique au `COMITE_AIT` |
| Score 14–17 | Statut passe en `a_valider_saholty` avant toute transmission (blocage workflow) |
| Fiabilité C ou D **ET** Impact = Élevé | Génère une tâche "demande de corroboration" assignée à l'émetteur |
| Crédibilité = 3 **ET** Urgence = Immédiat/72h | Flag `surveillance_renforcee = true`, alerte reste visible en tête de dashboard tant que non résolue |

**Garde-fous techniques obligatoires (Annexe C §"Garde-fous") :**
1. Les champs `elements_factuels` et `hypotheses_analytiques` sont **deux colonnes distinctes en base**, jamais concaténées — validation Symfony au niveau Form + Entity constraint.
2. Aucune action "coercitive" n'existe dans le système : les statuts et boutons se limitent à *qualifier / transmettre / archiver / surveiller* — jamais "saisir", "arrêter", etc.
3. Une transmission externe ne peut être marquée `transmission = oui` sans qu'un utilisateur ayant le rôle `PFT` **ou** `SAHOLTY` valide (double contrôle configurable selon sensibilité).

### 3.3 Règles de statut / workflow (Symfony Workflow Component)

```
nouveau → en_cours → [a_completer | actionnable] → [transmis | archive] → clos
                 ↘ a_investiguer ↗
```
Chaque transition logge automatiquement dans `alert_status_history` (qui, quand, ancien→nouveau statut, justification obligatoire si passage à `clos`).

### 3.4 SLA automatique "Urgence 72h" (Annexe E — moteur de minuteurs)

| Phase | Fenêtre | Responsable | Déclencheur automatique en cas de retard |
|---|---|---|---|
| 0. Détection | T0 | Émetteur | création de la Fiche = T0, horodatage immuable |
| 1. Qualification rapide | T0 → T+6h | PFT + SAHOLTY | si non qualifiée à T+5h : rappel automatique ; à T+6h dépassé : flag `en_retard=true`, escalade Secrétariat |
| 2. Validation & orientation | T+6h → T+24h | SAHOLTY + PFT | idem, rappel à T+22h |
| 3. Coordination opérationnelle | T+24h → T+48h | SAHOLTY | idem |
| 4. Suivi & clôture | T+48h → T+72h | PFT + Secrétariat | à T+72h, si non clos : notification `COMITE_AIT` automatique |

**Implémentation** : tâche planifiée (Symfony Scheduler ou cron + Messenger) qui scanne toutes les `urgence_72h_phase` actives toutes les 15 minutes et déclenche les notifications de rappel/retard.

### 3.5 Règles de confidentialité & accès (Annexe D — à coder en Voter Symfony)

- **Besoin d'en connaître** : un `EMETTEUR_TERRAIN` ne voit que les alertes de son `market_id`. Un `PFT` voit son marché + agrégats anonymisés des autres. `SAHOLTY` et `SUPERADMIN` voient tout.
- **Anonymisation par défaut** : le champ source réelle n'est jamais exposé dans l'API/UI standard ; un bouton "Voir source" n'est actif que pour `SAHOLTY_ADMIN`, avec MFA + log immédiat dans `access_log`.
- **Rétention** : job mensuel qui archive (ne supprime pas) les alertes `clos` depuis > durée paramétrable (ex. 24 mois), avec trace de suppression si applicable (Annexe D §9).

---

## 4. DASHBOARDS À CONCEVOIR

### 4.1 Dashboard opérationnel (PFT / SAHOLTY — vue quotidienne)
- Cartes KPI en tête : nb alertes ouvertes, nb `urgence 72h` en cours (avec compte à rebours par phase), taux de retard SLA, score moyen du mois.
- Table filtrable (pays, statut, urgence, score, période) = **vue directe du registre** (reproduit fidèlement les colonnes du fichier Excel source).
- Vue "matrice de priorisation" (heatmap Fiabilité × Crédibilité, reprenant exactement le tableau visuel Annexe C).
- Vue carte/corridor (regroupement géographique des corridors : Cotonou–Nigeria, Tema–Niger, Widana, etc.) — utile vu la récurrence de ces corridors dans le registre analysé.

### 4.2 Dashboard KPI / Comité AIT (vue stratégique, mensuelle)
Reprend exactement les indicateurs listés en Annexe E §14 :
- Délai moyen détection → qualification
- Délai moyen qualification → transmission
- Taux d'alertes exploitables (`exploitabilite = actionnable` / total)
- Taux de traitement dans les 72h
- Qualité des informations transmises (proxy : % alertes fiabilité A/B)
- Tendances par pays / par marque / par corridor (données réelles du registre s'y prêtent bien : BOHEM, YES, ESSE EDGE, AVENTUS NIGER SARL reviennent sur plusieurs alertes → graphe "acteurs récurrents").

### 4.3 Dashboard Urgence 72h (vue temps réel)
- Liste des cas actifs avec barre de progression des 5 phases et minuteur visuel (vert/orange/rouge selon marge SLA restante).
- Historique des cas clos avec délai réel vs délai cible.

### 4.4 Dashboard Superadmin
- Gestion utilisateurs/rôles/habilitations (CRUD + historique d'attribution).
- Vue `access_log` complète, filtrable, exportable (obligation de traçabilité Annexe D).
- Gestion des incidents de sécurité (`incident_security`) avec statut de traitement.
- Paramétrage des seuils du moteur de règles (§3.1/3.2) sans toucher au code (table `rule_config` éditable en interface).

---

## 5. FORMULAIRES À CONCEVOIR (Symfony Form + validation)

| Formulaire | Base documentaire | Points clés d'implémentation |
|---|---|---|
| **Créer/éditer une Fiche d'alerte** | Annexe A intégrale | Formulaire multi-étapes (wizard, 8 sections = 8 steps), auto-save brouillon, génération auto du `code_gei`, checklist "pièces disponibles" en checkboxes. |
| **Qualification GEI** | Annexe C | Formulaire réservé PFT/SAHOLTY ; champs fiabilité/crédibilité/urgence/impact/exploitabilité → score affiché en **live** (calcul JS miroir + recalcul serveur faisant foi). |
| **Décision de transmission** | Annexe A §7 + Annexe D §6 | Sélection destinataires (liste fermée : Douanes/Police/Autorités fiscales/Partenaires), déclenche workflow de double validation si score 14–17. |
| **Déclaration d'incident de sécurité** | Annexe D §10 | Formulaire simplifié accessible à tout utilisateur connecté, notification immédiate PFT + SAHOLTY, jamais visible par `EMETTEUR_TERRAIN` autre que le déclarant. |
| **Activation Urgence 72h** | Annexe E Phase 0 | Se déclenche automatiquement si règle §3.2 remplie, mais peut aussi être activé manuellement par un PFT avec justification obligatoire. |
| **Clôture d'alerte / de cas urgence** | Annexe E Phase 4 | Champ obligatoire "capitalisation des enseignements", empêche la clôture si pièces jointes manquantes et `exploitabilite = actionnable`. |
| **Export registre (.xlsx)** | Fichier registre source | Bouton "Exporter" → génère un fichier structurellement identique au fichier Annexe B fourni (mêmes colonnes, même ordre), filtrable par période/pays/statut. |

---

## 6. RÈGLES DE GESTION TRANSVERSALES (À PRÉSENTER AU CLIENT)

1. **Séparation stricte faits / hypothèses / recommandations** dans le modèle de données et l'affichage (jamais de champ unique fourre-tout).
2. **Score et priorité toujours calculés par le système, jamais saisis manuellement** — garantit la cohérence de traitement entre tous les pays du dispositif.
3. **Anonymisation par défaut, non désactivable sans habilitation spécifique + double authentification.**
4. **Aucune fonctionnalité d'action coercitive** : la plateforme est un outil de qualification/transmission d'information, jamais un outil de décision opérationnelle (conforme Annexe C "outil d'aide à la décision, non substitut aux autorités compétentes").
5. **Traçabilité intégrale** : toute lecture, modification, export ou transmission est journalisée sans exception.
6. **SLA automatisés et non contournables** : les délais de la procédure 72h sont calculés et affichés par le système, avec escalade automatique en cas de dépassement — supprime la dépendance à un suivi manuel.
7. **Registre = source de vérité unique** : le fichier Excel devient une **vue exportée** du système, jamais une saisie parallèle (fin de la double saisie et des risques d'incohérence observés entre les colonnes du fichier fourni, ex. doublons d'ID GEI-BEN-2026-017/018 dans le fichier source).

---

## 7. CHECKLIST DE DÉVELOPPEMENT (À SUIVRE POUR L'AGENT IA / L'ÉQUIPE DEV)

### Phase 1 — Fondations
- [ ] Initialiser projet Symfony 7 + API Platform + PostgreSQL
- [ ] Mettre en place Symfony Security + JWT + 2FA
- [ ] Créer les entités : `User`, `Role`, `Permission`, `Market`, `FocalPoint`
- [ ] Implémenter les Voters de contrôle d'accès (need-to-know)
- [ ] Mettre en place l'audit automatique (`access_log`, Loggable)

### Phase 2 — Cœur métier (Alertes)
- [ ] Créer l'entité `Alert` + toutes les entités liées (§2.2)
- [ ] Implémenter le formulaire wizard "Fiche d'alerte" (Annexe A)
- [ ] Implémenter `ScoreCalculatorService` (formule §3.1) + tests unitaires sur les cas réels du registre fourni
- [ ] Implémenter `EscalationRuleEngine` (§3.2) avec table `rule_config` paramétrable
- [ ] Configurer le Workflow Symfony pour les statuts (§3.3)

### Phase 3 — Urgence 72h
- [ ] Créer `Urgence72hCase` / `Urgence72hPhase`
- [ ] Implémenter le scheduler de vérification SLA (toutes les 15 min)
- [ ] Notifications (email + in-app) pour rappels et dépassements
- [ ] Dashboard temps réel avec minuteurs

### Phase 4 — Dashboards & reporting
- [ ] Dashboard opérationnel PFT/SAHOLTY
- [ ] Dashboard KPI Comité AIT
- [ ] Dashboard Urgence 72h
- [ ] Dashboard Superadmin (utilisateurs, logs, incidents, config des règles)
- [ ] Export registre .xlsx (PhpSpreadsheet) fidèle au format source

### Phase 5 — Confidentialité & conformité
- [ ] Chiffrement des champs identité source (`source_identity`)
- [ ] Politique de rétention/archivage automatisée
- [ ] Module de gestion des incidents de sécurité (Annexe D §10)
- [ ] Génération PDF de la Fiche d'alerte (mise en page identique Annexe A)

### Phase 6 — Recette & présentation client
- [ ] Jeu de données de démonstration basé sur les ~90 alertes réelles du registre fourni (anonymisées si nécessaire pour la démo)
- [ ] Vérifier que le score calculé par le système correspond aux valeurs `Crédibilité`/`Urgence`/etc. déjà présentes dans le registre source (contrôle de cohérence)
- [ ] Préparer le support de présentation client : schéma DB (§2), règles de gestion (§3 et §6), captures des dashboards
- [ ] Tests de charge sur le calcul de score en masse (import registre complet)

---

## 8. NOTE POUR LA PRÉSENTATION CLIENT

Le point fort à mettre en avant : **la plateforme transforme un dispositif aujourd'hui géré par formulaires Word/Excel et emails sécurisés en un système où le calcul de score, l'escalade, les délais SLA et la traçabilité sont 100% automatisés et non contournables** — ce qui répond directement aux exigences déjà formalisées par le client dans ses propres annexes (C, D, E), sans rien y ajouter d'exogène. C'est un argument de vente fort : *"nous digitalisons exactement vos règles existantes, nous ne vous en imposons pas de nouvelles."*
