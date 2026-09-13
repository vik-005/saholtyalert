# Audit des champs redondants — Entité Alert et Formulaires GEI
**Date :** 2026-09-08  
**Auteur :** Implémentation automatique — Prompt Expert Final  
**Référence :** Partie E du prompt de finalisation

---

## Méthodologie

Revue exhaustive de tous les champs de `src/Entity/Alert.php` et des deux formulaires (`AlertType.php`, `QualificationType.php`) en croisant :
- L'usage réel (formulaires, templates, exports, filtres)
- Les commentaires dans le code
- L'historique des migrations successives (V20260817 → V20260904)

---

## 1. Champs analysés — Décision et justification

| Champ | Type | Analyse | Décision |
|-------|------|---------|----------|
| `commentaires` | TEXT | Notes libres Manager sur la qualification. Utilisé dans QualificationType et affiché dans show.html.twig sous "Commentaires Manager". | ✅ **Conserver** — usage distinct |
| `decisionGei` | TEXT | Décision formelle (Transmission / Archivage) — champ 18 de l'Annexe A, réservé Manager. Ajouté en migration V20260831. | ✅ **Conserver** — usage distinct de `commentaires` |
| `scoreGei` | INT | Score calculé automatiquement par `ScoreCalculatorService`. Jamais saisi manuellement. | ✅ **Conserver** — champ calculé |
| `scoreSurcharge` | INT | Score remplacé manuellement par le Manager avec justification. Intentionnellement distinct de `scoreGei` (`getEffectiveScore()` retourne `scoreSurcharge ?? scoreGei`). | ✅ **Conserver** — paire intentionnelle |
| `niveauPriorite` | Enum | Priorité dérivée du score, calculée automatiquement. | ✅ **Conserver** — champ calculé |
| `niveauPrioriteSurcharge` | Enum | Priorité remplacée manuellement. Même logique que `scoreSurcharge`. | ✅ **Conserver** — paire intentionnelle |
| `piecesDisponibles` | BOOLEAN | Flag binaire "pièces disponibles Oui/Non". | ✅ **Conserver** — sémantique distincte de `piecesType` |
| `piecesType` | VARCHAR(100) | Type de pièces disponibles (ex. "manifeste"). Colonne R du registre Annexe B. Complément de `piecesDisponibles`, pas un doublon. | ✅ **Conserver** — champs complémentaires |
| `emetteurTexte` | VARCHAR(200) | Texte libre de l'émetteur pour les imports Excel quand l'utilisateur n'existe pas en base. Distinct de la relation `emetteur` (FK User). | ✅ **Conserver** — sécurité import, historique |
| `referenceDocumentaire` | VARCHAR(100) | Référence interne du poste de l'Agent (ex. "ALT-BJA02-2026-001"). Distinct du `codeGei` (généré automatiquement par la plateforme). | ✅ **Conserver** — deux systèmes de référence légitimement coexistants |
| `actionsEnCours` | TEXT | Actions terrain en cours. Rempli par Agent ET Manager selon le contexte. | ✅ **Conserver** |
| `historiqueSource` | TEXT | Historique comportemental de la source. Section 3 de l'Annexe A. Distinct de `typeSource`. | ✅ **Conserver** |
| `responsableSuivi` | ManyToOne User | Responsable de suivi assigné. Distinct de `emetteur` (soumetteur) et `validatedBy` (validateur). | ✅ **Conserver** |
| `commentaireRejet` | TEXT | Commentaire obligatoire lors d'un rejet. Affiché en priorité en rouge sur la fiche. | ✅ **Conserver** — usage distinct de `commentaires` |

---

## 2. Champs nouveaux ajoutés (prompt expert final)

| Champ | Justification |
|-------|---------------|
| `validatedBy` (FK User) | Traçabilité nominative Manager (Partie B) |
| `dateValidation` (DATETIME) | Horodatage de validation (Partie D) |

---

## 3. Conclusion — Aucun champ à supprimer

Après analyse exhaustive, **aucun champ n'a été identifié comme véritablement redondant**. Les paires qui pourraient sembler doubles (`scoreGei`/`scoreSurcharge`, `niveauPriorite`/`niveauPrioriteSurcharge`, `piecesDisponibles`/`piecesType`) sont intentionnelles par design et documentées dans le code.

Les deux textes libres de commentaire (`commentaires` et `commentaireRejet`) ont des sémantiques distinctes et des affichages séparés.

---

## 4. Points de vigilance pour les évolutions futures

1. **`decisionGei`** (TEXT) n'est actuellement pas affiché dans le formulaire Manager de QualificationType. Si la décision formelle (Transmission/Archivage) doit être tracée séparément du statut workflow, ce champ doit être surfacé. Sinon il peut être déprécié lors d'une prochaine itération.

2. **`emetteurTexte`** ne sera plus nécessaire si tous les imports Excel utilisent désormais la résolution d'utilisateurs existants. À archiver (colonne conservée, non affichée) quand l'import sera refactorisé.

3. **Le statut est exclusivement piloté par Symfony Workflow** — ne jamais en faire un champ libre dans les formulaires.

---

*Document généré automatiquement le 2026-09-08 dans le cadre du Prompt Expert Final GEI.*
