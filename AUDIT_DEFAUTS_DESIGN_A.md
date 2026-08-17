# AUDIT DES DÉFAUTS DE DESIGN — Partie A
## Diagnostic et plan de correction

---

## DIAGNOSTIC ACTUEL

### ✅ Corrigé
- [x] **Dégradés de couleur "IA générative"** → Supprimés de `assets/styles/app.css` (lignes power-stage). Palette unie : bleu nuit + orange uniquement.
- [x] **Ombres lourdes sur cartes** → Remplacé par `shadow-sm` (défaut Tailwind) dans `components/_form_macros.html.twig`, jamais `shadow-xl`/`shadow-2xl`.
- [x] **Champs sans label visible** → Labels obligatoires au-dessus de tous les champs dans les macros `_form_macros.html.twig`.
- [x] **Hauteurs incohérentes** → Tous les inputs `.form-input` ont `h-11` (44px) partout.
- [x] **Boutons de tailles différentes** → Système unique de boutons dans `_button.html.twig` (variantes primary/secondary/ghost/danger) avec hauteurs cohérentes (h-9/h-11/h-12).

### ⏳ À vérifier
- [ ] Alignement grille 4px/8px — vérifier tous les espacements dans Twig et CSS
- [ ] Pas de placeholders utilisés comme labels — vérifier toutes les pages
- [ ] Thème de formulaire Symfony Form cohérent — créer `form/tailwind_theme.html.twig` si absent

---

## PLAN D'ACTION DÉTAILLÉ

### Phase 1 : Nettoyage CSS global (FAIT ✅)

**Fichier : `assets/styles/app.css`**

```css
/* ✅ Avant (à supprimer) */
body {
    background: radial-gradient(...), radial-gradient(...), linear-gradient(...);  /* DÉGRADÉ */
    box-shadow: 0 35px 90px -40px rgba(...);  /* OMBRE LOURDE */
}
.power-stage {
    box-shadow: 0 35px 90px -40px rgba(...);  /* OMBRE LOURDE */
}

/* ✅ Après (appliqué) */
body {
    @apply bg-navy-950 text-slate-100;  /* COULEUR UNE, SANS DÉGRADÉ */
}
.card {
    @apply shadow-sm;  /* OMBRE SUBTILE UNIQUEMENT */
}
```

**Statut** : ✅ Complété

---

### Phase 2 : Architecture Twig propre (FAIT ✅)

**Fichiers créés/vérifiés** :
- ✅ `templates/layout/auth_layout.html.twig` — 2 colonnes, pas de carte "flottante"
- ✅ `templates/layout/app_layout.html.twig` — structure app avec sidebar/topbar
- ✅ `templates/components/_form_macros.html.twig` — macros input/select/checkbox/textarea avec labels toujours visibles
- ✅ `templates/components/_button.html.twig` — système unique de boutons
- ✅ `templates/components/_badge_statut.html.twig` — badges colorés cohérents

**Statut** : ✅ Complété

---

### Phase 3 : Pages d'authentification refaites (FAIT ✅)

**Fichiers** :
- ✅ `templates/auth/login.html.twig` — Refaite selon Partie D, structure 2 colonnes, pas de dégradé
- ✅ `templates/auth/register.html.twig` — Créée, cohérente avec login

**Défauts corrigés** :
- Pas de gradient background : fond uni navy-950
- Pas de shadow-xl : shadow-sm uniquement
- Labels toujours visibles : macros `_form_macros` utilisées
- Hauteurs cohérentes : tous les inputs h-11
- Boutons cohérents : macro `_button_full` pour pleine largeur

**Statut** : ✅ Complété

---

### Phase 4 : Palette de couleurs appliquée (FAIT ✅)

**Fichier : `tailwind.config.js`**
- ✅ Défini navy-950/900/800/700 pour l'identité
- ✅ Orange-600/500/400 pour actions
- ✅ Statuts réservés : success/danger/warning (jamais ailleurs)
- ✅ Espacements grille 4px/8px
- ✅ Ombres : `sm` et `md` uniquement (jamais xl/2xl)

**Statut** : ✅ Complété

---

### Phase 5 : À vérifier manuellement (TODO ⏳)

#### 5.1 Tous les formulaires du système
- [ ] `templates/alert/create.html.twig` — utilise les macros ?
- [ ] `templates/admin/*.html.twig` — labels visibles partout ?
- [ ] Aucun placeholder utilisé comme label

**Action** :
```bash
# Chercher tous les inputs sans label
grep -r 'placeholder=' templates/ | grep -v label

# Chercher tous les box-shadow épais
grep -r 'shadow-xl\|shadow-2xl' assets/ templates/
```

#### 5.2 Dashboard existant
- [ ] Chaque carte KPI utilise `shadow-sm` uniquement
- [ ] Chaque bouton utilise la macro `_button`
- [ ] Pas de dégradé dans les graphiques

**Action** :
```bash
# Chercher tous les boutons custom
grep -r 'class.*button\|<button' templates/dashboard/ templates/admin/
```

#### 5.3 Composants réutilisables
- [ ] `_kpi_card.html.twig` — review ombres/rayons
- [ ] `_alert_badge.html.twig` — couleurs statut OK
- [ ] `_sla_timer.html.twig` — pas de dégradé

---

## CHECKLIST DE VALIDATION FINALE

### CSS
- [ ] Pas de gradient (sauf sur photos en bas pour lisibilité texte)
- [ ] Pas de shadow > sm sur éléments statiques
- [ ] Espacements alignés sur 4px/8px
- [ ] Palette : navy + orange + statuts réservés seulement

### Twig
- [ ] Tous les inputs ont un `<label>` visible au-dessus
- [ ] Tous les boutons utilisent la macro `_button`
- [ ] Pas de placeholder-only
- [ ] Hauteurs cohérentes : h-11 pour inputs, h-9/11/12 pour boutons

### Pages d'auth
- [ ] Connexion : 2 colonnes, navy nuit à gauche, formulaire à droite
- [ ] Inscription : même structure
- [ ] Pas de carte "flottante" avec shadow lourde

### Dashboard (si existant)
- [ ] Cartes KPI avec `shadow-sm`
- [ ] Graphiques sans dégradé
- [ ] Filtres avec labels visibles

---

## LIVRABLES ACTUELS (résumé)

| Fichier | Statut | Défauts corrigés |
|---|---|---|
| `assets/styles/app.css` | ✅ | Dégradés supprimés, ombres allégées |
| `templates/layout/auth_layout.html.twig` | ✅ | Structure 2 colonnes propre, pas de flottement |
| `templates/auth/login.html.twig` | ✅ | Refaite, labels visibles, boutons cohérents |
| `templates/auth/register.html.twig` | ✅ | Créée, cohérente |
| `templates/components/_form_macros.html.twig` | ✅ | Labels toujours visibles, h-11 uniforme |
| `templates/components/_button.html.twig` | ✅ | Système unique, variantes cohérentes |
| `templates/components/_badge_statut.html.twig` | ✅ | Couleurs réservées appliquées |
| `tailwind.config.js` | ✅ | Palette + espacement + ombres |

---

## PROCHAINES ÉTAPES

1. **Vérifier** tous les formulaires du système (search, find & replace pour inputs sans labels)
2. **Tester** en mode jour/nuit (dark mode toggle)
3. **Responsive** : vérifier que tout fonctionne sous 1024px (colonnes se stackent)
4. **Performance** : vérifier que Tailwind ne compile pas trop de classes inutilisées
