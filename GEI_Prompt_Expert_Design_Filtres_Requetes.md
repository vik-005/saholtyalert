# PROMPT EXPERT — REFONTE DESIGN PRO (AUTH + DASHBOARD) & AUDIT FILTRES/REQUÊTES
### À donner tel quel à l'IA de développement travaillant sur le dépôt Symfony/Twig
**Rôle assigné : designer UX/UI senior (50 ans d'expérience terrain, plateformes internationales) + développeur Symfony/Twig senior.**

---

## MISSION

Le design actuel des formulaires (inscription, connexion, et plus largement tous les champs de formulaire) est **amateur** : champs non stylés de façon cohérente, textes non alignés, aucune hiérarchie visuelle. Ta mission :

1. **Corriger la direction artistique** de tout le système (pas seulement l'auth) selon les règles anti-générique ci-dessous.
2. **Reconstruire les pages Inscription/Connexion** sur le modèle structurel de la maquette de référence fournie (panneau visuel à gauche + formulaire à droite), mais **avec la palette du site : bleu nuit + orange**, jamais le violet de la maquette source — la maquette n'est qu'une référence de structure, pas de couleur.
3. **Mettre en place une architecture Twig propre** : layout de base, layout d'authentification qui en hérite, thème de formulaire réutilisable, composants Tailwind.
4. **Auditer les dashboards existants** : vérifier que tous les filtres nécessaires au Manager sont présents, et fournir les requêtes exactes pour chaque graphique attendu.

---

## PARTIE A — CE QU'IL FAUT ARRÊTER DE FAIRE (diagnostic du rendu actuel)

Interdits explicites sur l'ensemble du système :
- Dégradés de couleur façon "IA générative" (violet→rose, bleu→cyan criard) — **seule exception tolérée** : un léger assombrissement en bas d'une photo pour la lisibilité du texte (traitement photo, pas un dégradé de fond d'interface).
- `box-shadow` épais et flous à la moindre carte (effet "flottant" artificiel). Une carte se distingue par une **bordure fine 1px** (`border-slate-200` en mode jour / `border-slate-700` en mode nuit) + une ombre **quasi imperceptible** (`shadow-sm`), jamais `shadow-xl`/`shadow-2xl` sur des éléments statiques.
- Champs de formulaire sans label visible au-dessus, placeholders utilisés comme label (inaccessible et non professionnel), hauteurs de champs incohérentes d'un formulaire à l'autre.
- Textes non alignés sur une grille (chaque écart doit être un multiple de 4px ou 8px, jamais une valeur approximative choisie à l'œil).
- Boutons de tailles/rayons différents selon les pages — un seul système de bouton (primaire, secondaire, tertiaire) réutilisé partout.

---

## PARTIE B — PALETTE FINALE (bleu nuit + orange, sans dégradé)

```
Bleu nuit (identité, panneau visuel, headers, texte fort) :
  --navy-950: #0B1220
  --navy-900: #0F1A2E
  --navy-800: #16233D
  --navy-700: #1F2F4F

Bleu pur (actions/liens, éléments interactifs secondaires) :
  --blue-600: #2563EB
  --blue-500: #3B82F6

Orange (action principale, accent, priorité modérée) :
  --orange-600: #EA580C
  --orange-500: #F97316
  --orange-400: #FB923C   (hover clair uniquement)

Statuts réservés (jamais utilisés ailleurs) :
  --success: #16A34A   (validé / transmis / dans les délais)
  --danger:  #DC2626   (critique / SLA dépassé)
  --warning: #D97706   (à compléter / attention)

Neutres jour :
  --bg: #F8FAFC   --card: #FFFFFF   --border: #E2E8F0   --text: #0F172A   --text-muted: #64748B

Neutres nuit :
  --bg: #0B1220   --card: #131C2E   --border: #253247   --text: #E2E8F0   --text-muted: #94A3B8
```

**Règle de dosage** : bleu nuit domine (structure, ~60%), blanc/neutre pour l'espace de respiration (~25%), orange en accent d'action uniquement (~10-15%, jamais en fond de grande zone).

---

## PARTIE C — ARCHITECTURE TWIG À METTRE EN PLACE

### C.1 Stack
- **Tailwind CSS** compilé via `symfony/webpack-encore` ou `symfony/asset-mapper` (au choix selon l'existant du projet — vérifier ce qui est déjà en place avant de dupliquer un outil de build).
- Police unique **Inter** (self-hosted dans `assets/fonts`, déclarée en `@font-face`, jamais Google Fonts CDN pour la prod).
- Pas de framework CSS de composants tiers (Bootstrap, etc.) mélangé à Tailwind — un seul système.

### C.2 Fichiers à créer/vérifier

```
templates/
 ├─ base.html.twig                 → squelette global (head, meta, blocks title/body/scripts)
 ├─ layout/
 │   ├─ auth_layout.html.twig      → extends base.html.twig, structure 2 colonnes (auth uniquement)
 │   └─ app_layout.html.twig       → extends base.html.twig, structure sidebar + topbar (dashboard)
 ├─ security/
 │   ├─ login.html.twig            → extends layout/auth_layout.html.twig
 │   └─ register.html.twig         → extends layout/auth_layout.html.twig   (si inscription publique existe ;
 │                                     sinon adapter en "création de compte Agent par le Manager")
 ├─ components/
 │   ├─ _input.html.twig           → macro champ texte (label + input + erreur)
 │   ├─ _select.html.twig          → macro select stylé
 │   ├─ _checkbox.html.twig        → macro checkbox stylée
 │   ├─ _button.html.twig          → macro bouton (variant: primary/secondary/ghost/danger)
 │   ├─ _kpi_card.html.twig        → carte indicateur dashboard
 │   ├─ _badge_statut.html.twig    → badge coloré selon statut/priorité (couleurs réservées §B)
 │   └─ _notification_bell.html.twig
 └─ form/
     └─ tailwind_theme.html.twig   → thème de formulaire Symfony Form (form_theme), pour que
                                       {{ form(form) }} sorte directement du HTML Tailwind cohérent
```

### C.3 `config/packages/twig.yaml` — enregistrer le thème de formulaire globalement
```yaml
twig:
    form_themes: ['form/tailwind_theme.html.twig']
```

### C.4 Exemple de macro composant (`components/_input.html.twig`)
```twig
{% macro input(name, label, type = 'text', value = '', error = null, required = false) %}
<div class="flex flex-col gap-1.5">
  <label for="{{ name }}" class="text-sm font-medium text-slate-700 dark:text-slate-200">
    {{ label }}{% if required %} <span class="text-orange-500">*</span>{% endif %}
  </label>
  <input
    type="{{ type }}" id="{{ name }}" name="{{ name }}" value="{{ value }}"
    class="h-11 px-3.5 rounded-lg border {{ error ? 'border-red-400 focus:ring-red-400' : 'border-slate-300 dark:border-slate-700 focus:ring-orange-500' }}
           bg-white dark:bg-navy-800 text-slate-900 dark:text-slate-100
           focus:outline-none focus:ring-2 focus:border-transparent transition-colors placeholder:text-slate-400"
  >
  {% if error %}<p class="text-xs text-red-500">{{ error }}</p>{% endif %}
</div>
{% endmacro %}
```
*(Même logique à répliquer pour select/checkbox/textarea — un seul point de vérité pour le style de chaque type de champ.)*

### C.5 Système de boutons (`components/_button.html.twig`)
```twig
{% macro button(label, variant = 'primary', type = 'submit', icon = null) %}
  {% set base = 'h-11 px-5 rounded-lg text-sm font-semibold transition-colors inline-flex items-center justify-center gap-2' %}
  {% set variants = {
    primary:   'bg-orange-500 hover:bg-orange-600 text-white',
    secondary: 'bg-navy-900 hover:bg-navy-800 text-white',
    ghost:     'border border-slate-300 dark:border-slate-700 text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-navy-800',
    danger:    'bg-red-600 hover:bg-red-700 text-white'
  } %}
  <button type="{{ type }}" class="{{ base }} {{ variants[variant] }}">{{ label }}</button>
{% endmacro %}
```

---

## PARTIE D — SPÉCIFICATION EXACTE DES PAGES CONNEXION / INSCRIPTION (CRÉATION DE COMPTE)

**Structure reprise de la maquette de référence (2 colonnes), adaptée à la palette du site :**

- **Colonne gauche (≈45% largeur, cachée sous 1024px)** : fond **bleu nuit uni** (`--navy-900`), pas de dégradé. Un visuel institutionnel discret (photo desaturée avec léger voile navy en bas pour le texte, OU motif géométrique subtil — pas de photo stock générique "IA"). Logo GEI en haut à gauche, en blanc. En bas : une phrase d'accroche institutionnelle courte (ex. "Qualifier l'information. Sécuriser la décision."), texte blanc, taille généreuse mais sobre.
- **Colonne droite (≈55%, pleine largeur sous 1024px)** : fond neutre clair (mode jour) / navy foncé carte (mode nuit). Formulaire centré verticalement, largeur max ~420px.
  - Titre H1 ("Connexion" / "Créer un compte agent") en bleu nuit, poids semi-bold.
  - Sous-titre gris discret.
  - Champs via les macros C.4 (label toujours visible au-dessus, jamais placeholder-only).
  - Bouton primaire orange pleine largeur (macro C.5, variant `primary`).
  - **Pas de "Or register with Google/Apple"** — ce système est un outil professionnel fermé (comptes créés par le Manager), retirer tout bloc de connexion sociale de la maquette de référence, il n'a pas de sens ici.
  - Lien texte discret en bas si pertinent (ex. "Mot de passe oublié ?").
- Aucune ombre lourde sur le panneau global — le panneau de connexion est une simple page, pas une "carte flottante sur fond flouté" comme la maquette de référence (ce flottement est un des marqueurs "IA générique" à éviter) : **plein écran, deux blocs pleine hauteur, pas de carte centrée avec ombre portée.**

---

## PARTIE E — AUDIT DES FILTRES DASHBOARD MANAGER (à vérifier/compléter dans le code existant)

Pour chaque écran, vérifier que **tous** ces filtres sont présents, combinables, et reflétés dans l'URL (query string) pour permettre le partage d'une vue filtrée :

### E.1 Écran "Registre / Liste des alertes"
- [ ] Marché(s) — **multi-sélection** (rappel : un Manager gère plusieurs marchés)
- [ ] Statut (brouillon/soumis/en_qualification/en_attente_complement/actionnable/transmis/archive/clos) — multi-sélection
- [ ] Niveau de priorité (critique/élevé/modéré/faible) — multi-sélection
- [ ] Plage de score (min-max, slider ou deux champs numériques)
- [ ] Urgence (immédiat/72h/routine)
- [ ] Type d'alerte (opérationnelle/stratégique)
- [ ] Période — plage de dates avec presets rapides (aujourd'hui / 7 jours / 30 jours / mois en cours / personnalisé)
- [ ] Agent émetteur (select recherchable, dépend du/des marché(s) sélectionné(s))
- [ ] Origine (saisie_agent / import_excel)
- [ ] Recherche texte libre (code alerte, corridor, mots du résumé — full-text)
- [ ] Cas urgence 72h uniquement (toggle)
- [ ] Tri (date décroissante par défaut, score décroissant, SLA restant croissant)
- [ ] Bouton "Réinitialiser les filtres" + compteur de résultats visible en permanence

### E.2 Écran "KPI / Pilotage"
- [ ] Marché(s) — multi-sélection, avec option "Tous marchés"
- [ ] Période comparative (semaine glissante / mois glissant / trimestre / année) avec comparaison à la période précédente (variation %)
- [ ] Filtre par niveau de priorité pour isoler l'analyse sur les cas critiques

### E.3 Écran "Urgence 72h"
- [ ] Statut du cas (actif / clôturé)
- [ ] Marché(s)
- [ ] Filtre "en retard uniquement" (toggle)
- [ ] Tri par SLA restant croissant (par défaut — le plus urgent en premier)

### E.4 Écran "Gestion des agents"
- [ ] Marché(s)
- [ ] Statut du compte (actif/désactivé)
- [ ] Recherche par nom/email
- [ ] Tri par volume de soumissions (identifie les agents les plus actifs)

---

## PARTIE F — REQUÊTES/AGRÉGATIONS POUR LES GRAPHIQUES DU DASHBOARD KPI

*(En s'inspirant de la structure de la maquette de référence — cartes indicateurs + courbe + donut + liste récente — mais avec les données GEI. Écrire ces requêtes en DQL/QueryBuilder Doctrine, agrégées et mises en cache — voir automatisation #15 du document de spécification V2.)*

### F.1 Cartes indicateurs (haut de dashboard, équivalent Income/Expense/Savings/Investment)
| Carte | Requête (logique) |
|---|---|
| Alertes soumises (période) | `COUNT(alert) WHERE date_soumission BETWEEN :debut AND :fin AND marche IN (:marches)` + comparaison à la période précédente identique en durée |
| Alertes traitées (transmis+archive+clos) | `COUNT(alert) WHERE statut IN (...) AND updated_at BETWEEN :debut AND :fin` |
| Taux de transmission | `COUNT(statut='transmis') / COUNT(statut IN ('transmis','archive')) * 100` |
| Score moyen | `AVG(score_gei) WHERE date_soumission BETWEEN :debut AND :fin` |

### F.2 Courbe temporelle (équivalent "Cashflow")
Alertes soumises vs alertes qualifiées, par jour, sur la période sélectionnée :
```sql
SELECT DATE(date_soumission) AS jour, COUNT(*) AS nb_soumises
FROM alert WHERE marche_emetteur IN (:marches) AND date_soumission BETWEEN :debut AND :fin
GROUP BY DATE(date_soumission) ORDER BY jour;

SELECT DATE(updated_at) AS jour, COUNT(*) AS nb_qualifiees
FROM alert WHERE statut NOT IN ('brouillon','soumis') AND updated_at BETWEEN :debut AND :fin
GROUP BY DATE(updated_at) ORDER BY jour;
```
Les deux séries sont superposées sur un même graphique en aires (Chart.js/ApexCharts), une couleur bleu nuit (soumises), une couleur orange (qualifiées) — jamais vert/rouge ici, ces couleurs sont réservées aux statuts.

### F.3 Donut de répartition (équivalent "Expense Breakdown")
Répartition des alertes actives par niveau de priorité :
```sql
SELECT niveau_priorite, COUNT(*) AS nb
FROM alert WHERE statut NOT IN ('clos','archive') AND marche_emetteur IN (:marches)
GROUP BY niveau_priorite;
```
Couleurs : critique = `--danger`, élevé = `--orange-500`, modéré = `--blue-500`, faible = `--navy-700` (dégradé de gravité cohérent, pas de couleurs arbitraires).

### F.4 Table "Alertes récentes" (équivalent "Recent Transactions")
```sql
SELECT code_alerte, marche_emetteur, agent.nom, statut, score_gei, updated_at
FROM alert JOIN user agent ON alert.agent_id = agent.id
WHERE marche_emetteur IN (:marches)
ORDER BY updated_at DESC LIMIT 8;
```

### F.5 Suivi des cas urgence 72h (équivalent "Saving Plans" — barres de progression)
```sql
SELECT c.id, a.code_alerte, c.date_activation,
       TIMESTAMPDIFF(HOUR, c.date_activation, NOW()) AS heures_ecoulees,
       72 AS heures_cibles
FROM urgence_72h_case c JOIN alert a ON c.alert_id = a.id
WHERE c.statut_case = 'active' AND a.marche_emetteur IN (:marches)
ORDER BY heures_ecoulees DESC;
```
Barre de progression = `heures_ecoulees / 72 * 100`, couleur orange sous 80%, rouge (`--danger`) au-delà.

### F.6 Activité récente (équivalent "Recent Activities")
```sql
SELECT action, user.nom, alert.code_alerte, date_action
FROM access_log JOIN user ON access_log.user_id = user.id
LEFT JOIN alert ON access_log.alert_id = alert.id
WHERE date_action >= :debut_periode
ORDER BY date_action DESC LIMIT 10;
```

**Performance** : toutes les requêtes d'agrégation (F.1 à F.3, F.5) doivent être mises en cache (Symfony Cache, TTL court ~5 min ou invalidation ciblée à chaque écriture sur `alert`), jamais recalculées à chaque chargement de page sur de gros volumes.

---

## PARTIE G — LIVRABLE ATTENDU

1. Nouvelle arborescence Twig conforme à la Partie C, avec composants réutilisés sur **toutes** les pages (pas seulement auth).
2. Pages Connexion/Création de compte refaites selon la Partie D, testées en mode jour/nuit et responsive.
3. Confirmation, section par section, que chaque filtre de la Partie E est implémenté et fonctionnel (avec capture ou description de l'UI).
4. Chaque requête de la Partie F implémentée, avec le point de mise en cache identifié.
5. Aucune trace des défauts listés en Partie A ne doit subsister sur l'ensemble du système, pas seulement sur les pages d'auth — balayer aussi le dashboard existant et les formulaires de qualification déjà codés.
