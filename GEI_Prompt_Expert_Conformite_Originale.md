# PROMPT EXPERT — VÉRIFICATION DE CONFORMITÉ AUX DOCUMENTS ORIGINAUX
### À donner tel quel à l'IA de développement (Claude Code / Cursor) sur le dépôt Symfony
**Rôle assigné : architecte logiciel senior (30 ans d'expérience, plateformes de données), garant de la fidélité fonctionnelle aux documents source du client.**

---

## ⚠️ CORRECTIF IMPORTANT DE RÉFÉRENCE DOCUMENTAIRE

Les échanges précédents ont, à un moment, utilisé un document **simplifié** (`ANNEXE_A_-_ALERTE_MODELE.docx`) comme base de répartition des champs Agent/Manager. **Ce n'est plus la référence.** La seule source de vérité désormais est :

- **`ANNEXE_A___Fiche_D_alerte_Standard_GEI.docx`** (fiche complète en 8 sections) — le formulaire réel à reproduire dans le code, à l'identique dans son contenu et son ordre.
- **`ANNEXE_C___Matrice_De_Qualification_GEI.docx`** — la logique de scoring officielle et définitive.
- **Le registre Excel `Annexe B`** — la structure de données réelle déjà en production chez le client, à ne jamais dérouter.

**Ta première tâche est de vérifier, dans le code existant, que ces trois documents sont bien ceux implémentés — et non la version simplifiée. Si le code actuel reflète l'ancienne version simplifiée, il faut le corriger pour revenir strictement à la fiche standard en 8 sections ci-dessous.**

---

## PARTIE A — RÉPARTITION EXACTE DES CHAMPS (Agent / Manager) selon la Fiche Standard originale

L'analyse section par section de `ANNEXE_A___Fiche_D_alerte_Standard_GEI.docx`, croisée avec la logique de `ANNEXE_C`, donne la répartition suivante — **c'est la logique que le formulaire de saisie doit respecter dans le code, à vérifier point par point :**

| Section de la fiche | Champs | Rempli par | Justification |
|---|---|---|---|
| **(1) Identification & traçabilité** | ID GEI | **Système** (jamais saisi manuellement) | Génération automatique, cf. Partie C |
| | Date de création | **Système** (horodatage automatique à la création) | — |
| | Émetteur (fonction/pays), Zone, Port/Corridor, Catégorie | **AGENT** | Constat terrain, l'agent est la seule personne en position de le renseigner |
| | Statut | **Système** (piloté par le workflow, jamais un champ libre) | — |
| **(2) Résumé exécutif** | Texte max 5 lignes | **AGENT** | Rédaction du constat |
| **(3) Source & fiabilité** | Type de source, Niveau d'anonymisation, Historique source | **AGENT** | L'agent connaît son propre contact/source |
| | **Fiabilité de la source (A–D)** | **AGENT** | Point clé : c'est l'agent qui a la relation avec la source et qui est le mieux placé pour juger de son historique de fiabilité (cohérent avec le champ voisin "Historique source" rempli par lui). **Ce champ nourrit directement la formule de score (Annexe C)**, mais sa saisie reste une déclaration terrain, pas une analyse. |
| **(4) Éléments factuels & analyse** | Éléments factuels, description du flux, acteurs impliqués, chronologie, pièces disponibles | **AGENT** | Constat terrain, jamais d'interprétation à ce stade |
| **(5) Hypothèses analytiques** | Texte libre, distinct des faits | **AGENT** peut proposer une première hypothèse, **mais le Manager doit pouvoir la compléter/corriger** — champ à droit d'édition partagé, avec historique de qui a écrit quoi (cf. `alert_comment`) |
| **(6) Qualification GEI** | **Crédibilité du contenu (1–4), Urgence, Impact potentiel, Exploitabilité** | **MANAGER** | C'est l'analyse à froid du contenu par un tiers, distincte de la fiabilité déclarative de la source — exactement la logique de la matrice Annexe C, qui sépare bien "Fiabilité de la source" (déclaratif) et "Crédibilité du contenu" (analytique) |
| | Score synthétique | **Système, automatique, jamais optionnel malgré la mention "(optionnel)" du document papier** | Le document papier prévoit une case manuelle car il est pensé pour un usage non-informatisé ; dans la plateforme, ce champ **doit toujours être calculé par le système** dès que les 4 critères manager sont saisis — ne jamais laisser la possibilité de le taper à la main. |
| **(7) Recommandation & action** | Recommandation, décision de transmission, destinataires proposés, besoins complémentaires | **MANAGER** | Décision opérationnelle |
| **(8) Confidentialité & diffusion** | Niveau de sensibilité, restrictions de diffusion | **MANAGER** | Décision de gouvernance de l'information |

**Vérification à effectuer dans le code** : le formulaire de saisie Agent ne doit exposer QUE les sections 1 (hors ID/statut), 2, 3, 4 et une version éditable de la section 5. Le formulaire de qualification Manager doit exposer les sections 6, 7, 8 en écriture, **et toutes les sections précédentes en lecture ainsi qu'en édition libre** (rappel déjà établi : le Manager a un droit de correction total sur toute la fiche, à tout moment).

---

## PARTIE B — AUDIT DU MOTEUR DE SCORING (conformité stricte à l'Annexe C)

### B.1 Vérifier la table de conversion exacte
```
Fiabilité :   A = 4   |   B = 3   |   C = 2   |   D = 1
Crédibilité : 1 = 4   |   2 = 3   |   3 = 2   |   4 = 1     (1 = confirmé → valeur la plus haute)
Urgence :     Immédiat = 3   |   72h = 2   |   Routine = 1
Impact :      Élevé = 3   |   Moyen = 2   |   Faible = 1
Exploitabilité : Actionnable = 3   |   À compléter = 2   |   Archivage = 1
```
- [ ] Vérifier dans le code que la conversion de la Fiabilité utilise bien des **lettres A/B/C/D** (pas une échelle 1-4 numérique comme dans la version simplifiée abandonnée). Corriger le champ en base si nécessaire (`ENUM('A','B','C','D')`).
- [ ] Vérifier que la Crédibilité est bien **inversée** (1 = meilleure note = valeur 4) — c'est un piège fréquent d'implémentation, à tester explicitement avec un cas Crédibilité=1 qui doit contribuer 4 points, pas 1 point.

### B.2 Vérifier la formule
```
Score GEI = (Fiabilité × Crédibilité) + Urgence + Impact + Exploitabilité
```
- [ ] Test unitaire obligatoire : Fiabilité A (4) × Crédibilité 1 (4) = 16, + Urgence Immédiat (3) + Impact Élevé (3) + Exploitabilité Actionnable (3) = **Score 25** → doit être classé Critique.
- [ ] Test unitaire du plancher : Fiabilité D (1) × Crédibilité 4 (1) = 1, + Routine (1) + Faible (1) + Archivage (1) = **Score 4** → doit être classé Faible.

### B.3 Vérifier les seuils d'interprétation
| Score | Niveau |
|---|---|
| ≥ 18 | Critique |
| 14–17 | Élevé |
| 10–13 | Modéré |
| ≤ 9 | Faible |
- [ ] Vérifier que ces seuils sont lus depuis la table `rule_config` (éditable) et non codés en dur.

### B.4 Vérifier les règles d'escalade automatique (à tester une par une, ce sont des règles **obligatoires** selon l'Annexe C)
| # | Condition | Action attendue | Statut d'implémentation à vérifier |
|---|---|---|---|
| 1 | Score ≥ 18 | Activation automatique de la procédure urgence + notification immédiate | — |
| 2 | Urgence = Immédiat **ET** Impact = Élevé | Transmission prioritaire (flag distinct du score) | — |
| 3 | Exploitabilité = Actionnable **ET** Crédibilité ≥ 2 (donc valeur convertie ≥ 3, soit Crédibilité déclarée 1 ou 2) | Déclenche une tâche "analyse rapide GEI" assignée au Manager | — |
| 4 | Signal détecté sur plusieurs pays/corridors | Escalade automatique au Comité AIT | — |
| 5 | Score 14–17 | Blocage de la transmission tant qu'une validation GEI explicite n'est pas enregistrée | — |
| 6 | Fiabilité C ou D **ET** Impact = Élevé | Génère une tâche "demande de corroboration" | — |
| 7 | Crédibilité déclarée 3 **ET** Urgence élevée (Immédiat ou 72h) | Flag "surveillance renforcée", reste visible en tête de liste jusqu'à résolution | — |

- [ ] Pour chacune des 7 règles ci-dessus : écrire un test qui construit une alerte remplissant exactement la condition et vérifie que l'action attendue se déclenche — **pas une vérification visuelle, un test automatisé**.

### B.5 Vérifier le seuil de validation minimale (règle de gouvernance de l'Annexe C)
Le document précise : **"Validation minimale : PFT + 2 marchés + SAHOLTY"**.
- [ ] Vérifier avec le client si cette règle (qui suppose une organisation à plusieurs niveaux : Point Focal Technique, 2 marchés distincts, SAHOLTY régional) s'applique telle quelle dans le modèle à 2 rôles (Agent/Manager) déjà retenu, ou si elle est **volontairement simplifiée** parce que le Manager cumule ces fonctions. **Ne pas décider seul** : le signaler explicitement au client comme point d'écart assumé entre le document papier (pensé pour une organisation à plusieurs strates) et la plateforme (2 rôles). Documenter la décision prise dans le code (commentaire + changelog).

### B.6 Vérifier les garde-fous non négociables
- [ ] Séparation stricte Faits (section 4) / Hypothèses (section 5) / Recommandations (section 7) : trois champs distincts en base, jamais fusionnés à l'affichage ni à la saisie.
- [ ] Aucune fonctionnalité de "décision coercitive" dans l'interface (pas de bouton "Saisir", "Arrêter", etc. — uniquement qualifier/transmettre/archiver/surveiller).
- [ ] Protection systématique des sources : vérifier qu'aucune vue standard (y compris l'export Excel) n'affiche l'identité réelle de la source, uniquement les champs anonymisés de la section 3.

---

## PARTIE C — VÉRIFICATION DE LA GÉNÉRATION DE L'ID GEI (format exact du registre existant)

**Format confirmé par le registre réel du client (colonne "ID GEI") :** `GEI-{CODE PAYS}-{ANNÉE}-{SÉQUENCE SUR 3 CHIFFRES}`

Exemples réels observés dans le fichier Annexe B : `GEI-BEN-2026-001`, `GEI-BEN-2026-002`... Exemple donné par le client : `GEI-CIV-2026-003`.

### C.1 Algorithme exact à vérifier/implémenter
1. **Code pays** : l'utilisateur choisit la Zone dans une liste fermée reprenant les cases à cocher de la section 1 de l'Annexe A (`Bénin, Togo, Ghana, Côte d'Ivoire, Mali, Niger, Autre`). Le système traduit ce choix en code sur 3 lettres :
   ```
   Bénin → BEN   |   Togo → TGO   |   Ghana → GHA   |   Côte d'Ivoire → CIV
   Mali → MLI    |   Niger → NER  |   Autre → code à saisir manuellement par le Manager (3 lettres), validé unique
   ```
2. **Année** : automatique, prise sur la date système au moment de la création (`YYYY`), jamais saisie.
3. **Séquence** : le système recherche, **pour ce pays et cette année précis**, le dernier `ID GEI` déjà attribué (`SELECT MAX(sequence) WHERE code_pays = :pays AND annee = :annee`), et incrémente de 1. Si aucune alerte n'existe encore pour ce pays sur cette année, la séquence démarre à `001`.
4. **Format d'affichage** : séquence toujours sur 3 chiffres avec zéros de tête (`001`, `002`, ... `099`, `100`).

### C.2 Points à tester explicitement (non négociable)
- [ ] Créer une alerte Bénin en 2026 alors que la dernière alerte Bénin 2026 existante est `GEI-BEN-2026-005` → la nouvelle doit être `GEI-BEN-2026-006`.
- [ ] Créer une alerte Côte d'Ivoire alors qu'aucune alerte CIV n'existe encore en 2026 → doit produire `GEI-CIV-2026-001`.
- [ ] **Test de passage d'année** : dernière alerte Bénin 2026 = `GEI-BEN-2026-087` (exemple) → une alerte Bénin créée en janvier 2027 doit produire `GEI-BEN-2027-001`, pas `GEI-BEN-2027-088`.
- [ ] **Test de concurrence** : deux créations simultanées sur le même pays/année ne doivent jamais produire le même numéro (verrou transactionnel `SELECT ... FOR UPDATE` sur le compteur, ou contrainte `UNIQUE` en base avec retry applicatif).
- [ ] Le code une fois attribué **n'est jamais régénéré**, même si l'alerte change de statut ou est modifiée par le Manager.

### C.3 ⚠️ Ne pas confondre avec la colonne "Référence documentaire"
Le registre réel contient une **deuxième colonne de code**, distincte : `Référence documentaire` (ex. `ALT-BJA02-2026-001`), qui suit un format différent (avec un sous-code d'agence/poste, ex. "BJA02"). **Ne pas essayer de faire coïncider ou de générer automatiquement ce second code avec la même logique que l'ID GEI** — il s'agit vraisemblablement d'une référence de classement interne propre à chaque poste douanier. Recommandation : le traiter comme un champ texte libre optionnel, éventuellement pré-rempli avec un sous-code que l'agent choisit dans son profil (à confirmer avec le client — point à trancher, ne pas deviner la structure "BJA02").

---

## PARTIE D — VÉRIFICATION DU CYCLE SOUMISSION → NOTIFICATION → VALIDATION

Le déroulé exact attendu, à vérifier dans le code :

1. L'**Agent** remplit les sections 1 (hors ID/statut), 2, 3, 4, 5 (voir Partie A) via le formulaire.
2. L'Agent **soumet** → le statut passe automatiquement, l'ID GEI est généré à cet instant (pas avant, pour éviter de "consommer" un numéro sur un brouillon jamais soumis — **à trancher avec le client** : générer l'ID à la création du brouillon ou seulement à la soumission ? Recommandation d'expert : générer **à la soumission**, pour ne pas laisser de trous de numérotation sur des brouillons abandonnés).
3. Le **Manager** reçoit une **notification automatique** ("Nouvelle alerte soumise : {ID GEI}").
4. Le Manager ouvre la fiche, remplit les sections 6, 7, 8 (Partie A), le score se calcule en temps réel (Partie B).
5. Deux issues possibles :
   - **Validation** : le Manager confirme → le statut de l'alerte change (ex. `actionnable`/`transmis`/`archive` selon la décision), **l'Agent reçoit une notification de changement de statut** sur son tableau de bord personnel.
   - **Complément requis** : le Manager renvoie la fiche avec un commentaire obligatoire → l'Agent est notifié, la fiche redevient éditable sur les champs de collecte uniquement.
6. Le Manager peut ajouter des **notes de suivi** ("ce qu'il reste à faire") dans l'espace commentaires — visible par lui-même et consultable en lecture par l'Agent concerné.
7. **Tout le reste s'automatise** sans action humaine supplémentaire : calcul du score, déclenchement des règles d'escalade (Partie B.4), activation du suivi urgence 72h si applicable, mise à jour des indicateurs du dashboard, journalisation dans `access_log`.

**Vérification à effectuer** : parcourir ce cycle dans le code, étape par étape, et confirmer qu'aucune étape ne nécessite une action manuelle non prévue ici (ex. si le calcul du score nécessite aujourd'hui de cliquer sur un bouton "Calculer" au lieu d'être automatique au blur du champ, c'est une non-conformité à corriger).

---

## PARTIE E — SECTION "RENDU EXACT DU TABLEAU" (vue registre fidèle + édition + import Excel)

### E.1 Colonnes exactes du registre (confirmées depuis le fichier réel du client — à respecter à l'identique, dans cet ordre, comme gabarit d'import ET d'export)

```
1.  ID GEI
2.  Date
3.  Émetteur (fonction/pays)
4.  Zone (pays)
5.  Port / Corridor
6.  Catégorie
7.  Résumé court
8.  Type de source
9.  Anonymisation
10. Fiabilité (A–D)
11. Crédibilité (1–4)
12. Urgence
13. Impact
14. Exploitabilité
15. Statut
16. Transmission (Oui / Non / À valider)
17. Actions en cours
18. Pièces (Oui/Non + type)
19. Référence documentaire
20. Sensibilité
21. Dernière MAJ
22. Responsable suivi
23. Commentaires
```
*(Note : la colonne "Score GEI" et "Niveau de priorité" n'existent pas dans le registre papier d'origine — elles sont un ajout de la plateforme. Vérifier avec le client s'il souhaite les voir apparaître comme colonnes 24 et 25 dans l'export, ce qui est fortement recommandé pour la valeur ajoutée de l'outil, mais c'est un ajout à valider, pas à imposer silencieusement.)*

### E.2 Vue "Registre" dans le dashboard Manager
- [ ] Vérifier que le tableau affiché à l'écran reprend **exactement ces 23 colonnes** (+ Score/Priorité si validé), dans le même ordre, avec les mêmes intitulés — un Manager qui connaît le fichier Excel doit reconnaître immédiatement la même structure, sans effort de réapprentissage.
- [ ] Vérifier que chaque cellule du tableau à l'écran est **éditable en ligne** (double-clic ou icône crayon) pour le Manager, sans devoir ouvrir un formulaire séparé pour une correction mineure (ex. corriger une faute dans "Actions en cours") — édition rapide type tableur, avec sauvegarde immédiate et entrée dans `alert_status_history`/`alert_comment` si le champ modifié le justifie.
- [ ] Vérifier la présence de tous les filtres déjà spécifiés (document filtres/requêtes précédent) directement au-dessus de ce tableau.

### E.3 Import Excel — reconfirmation avec les colonnes exactes ci-dessus
- [ ] Le gabarit d'import attendu est **strictement identique** à la structure E.1 (23 colonnes). Vérifier que le contrôleur d'import valide bien la présence et l'ordre de ces colonnes avant tout traitement, et rejette clairement (avec message précis) un fichier dont l'en-tête ne correspond pas.
- [ ] À l'import, pour chaque ligne : le système lit `Fiabilité (A–D)` et `Crédibilité (1–4)` telles quelles dans le fichier, **recalcule systématiquement le Score GEI avec la formule officielle** (jamais de confiance dans un score déjà présent dans un fichier externe), et détermine le niveau de priorité.
- [ ] Vérifier la détection de doublons sur `ID GEI` (si la ligne importée porte un ID GEI déjà existant en base → proposer mise à jour plutôt que doublon) et sur similarité Corridor+Date+Résumé pour les lignes sans ID (nouvelles saisies en masse).
- [ ] Après import, les statistiques du dashboard (KPI, scores moyens, répartition par priorité) doivent immédiatement intégrer les nouvelles lignes — vérifier l'invalidation du cache d'agrégats.
- [ ] Un rapport d'import (lignes insérées / mises à jour / ignorées comme doublons / en erreur) doit s'afficher après traitement, avec le détail ligne par ligne des erreurs.

---

## PARTIE F — LIVRABLE ATTENDU

Pour chaque partie (A à E), produire un compte-rendu explicite :
1. **Conforme** — déjà bien implémenté, aucune action.
2. **Non conforme, corrigé** — préciser ce qui a été changé et dans quel fichier.
3. **Point à trancher avec le client** — ne rien deviner silencieusement sur les points explicitement signalés comme tels dans ce document (règle de validation à 3 niveaux de la Partie B.5, génération de l'ID à la création vs à la soumission en Partie D, structure du champ "Référence documentaire" en Partie C.3, ajout des colonnes Score/Priorité en Partie E.1).

Fournir également la liste des tests automatisés écrits ou complétés pour chacune des règles de scoring et d'escalade (Partie B.4) et pour la génération de l'ID GEI (Partie C.2) — ce sont les deux zones où une erreur silencieuse aurait le plus d'impact opérationnel pour le client.
