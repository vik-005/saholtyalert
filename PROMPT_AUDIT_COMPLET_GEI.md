# Prompt d'audit complet de la plateforme GEI

Tu es un agent senior en developpement web, UX/UI, Symfony, PHP, MariaDB, securite applicative et QA. Tu dois auditer et ameliorer toute la plateforme GEI avec un regard critique de produit fini professionnel. Ne te limite pas a commenter : reproduis les problemes, corrige le code, execute les tests et reverifie chaque correction dans le navigateur.

## Regles de travail obligatoires

1. Commence par inventorier les routes, menus, pages, controleurs, templates, formulaires, services, entites, repositories, migrations et tests.
2. Travaille par petits lots fonctionnels. Avant chaque correction, identifie le fichier qui controle reellement le comportement.
3. Ne supprime jamais une modification existante sans la comprendre.
4. Toute action signalee comme fonctionnelle doit etre testee reellement : clic, navigation, requete HTTP, validation, succes, erreur et retour utilisateur.
5. Pour chaque bug trouve : reproduire le bug, identifier le controleur et le template, corriger a la racine, ajouter ou ajuster un test, puis reexecuter le test et le parcours navigateur.
6. Verifier les erreurs PHP, Twig, JavaScript, SQL, les erreurs 404/403/500, les requetes echouees et les erreurs console.
7. Ne pas considerer un bouton visible comme fonctionnel tant que son action n'a pas ete verifiee.
8. Ne pas utiliser de donnees fictives pour masquer une fonctionnalite absente.
9. Conserver les conventions et le style visuel existants lorsqu'ils sont coherents.
10. Toute migration necessaire doit etre creee avec Doctrine puis executee sur la base locale. Verifier ensuite le schema reel et le statut des migrations.

## 1. Audit des pages et menus

Auditer toutes les routes accessibles et les menus principaux et secondaires : logo, accueil, menu lateral, menu haut, sous-menus, liens actifs, liens morts, boutons retour, fermer, annuler, reset et deconnexion.

Verifier que les menus et modales se ferment par croix, clic exterieur et touche Echap. Verifier aussi les liens vers les mauvaises routes, les permissions, les doublons, les textes tronques, les superpositions et les boutons masques. Tester desktop, tablette et mobile, ainsi que la navigation clavier, le focus visible, les labels accessibles et les tooltips des boutons icon-only.

Pour chaque bouton et lien, verifier le libelle ou aria-label, la route, l'endpoint, la methode HTTP, la confirmation avant suppression, le message de succes ou d'erreur, le retour vers la page attendue et le comportement en cas de double clic, session expiree ou permission insuffisante.

## 2. Actions CRUD et tableaux

Sur toutes les listes, fiches et pages show, tester voir, ajouter, modifier, supprimer, archiver/restaurer, exporter, imprimer/telecharger, rechercher, filtrer, trier et paginer.

Verifier que Modifier ouvre la bonne fiche avec les bonnes donnees, que Supprimer supprime uniquement la bonne ligne apres confirmation, que l'annulation ne supprime rien, que les droits sont controles cote serveur, que les tableaux gardent toutes leurs colonnes et lignes, et que les actions restent accessibles sur mobile.

Verifier les etats vide, chargement, erreur et succes. Les boutons Valider, Enregistrer, Confirmer et Cloturer doivent etre visibles, non ambigus et non bloques par un overlay.

## 3. Creation et insertion d'une alerte

Auditer tout le wizard de creation et d'edition, etape par etape : validation, preservation des valeurs, messages proches des champs, prevention des doubles soumissions, recapitulatif exact, insertion en base, redirection, affichage de l'alerte creee, erreurs de session, permissions et base de donnees.

Verifier Port, Corridor et Aeroport. La valeur type_localisation doit etre correctement enregistree a la creation, a la modification et a l'import. Ne jamais deduire silencieusement un aeroport comme corridor a cause d'une valeur NULL. Gerer Aeroport, Aéroport, aeroport, airport et aerodrome.

## 4. Parcours multi-pays dans P1

Revoir l'etape P1 avec un controle professionnel : dropdown searchable affichant tous les pays du monde, recherche en temps reel, selection multiple et tags visibles.

Chaque pays choisi devient un tag avec une croix accessible. Cliquer sur la croix retire uniquement ce pays. Un pays deja choisi ne doit pas etre propose en doublon. Le controle doit fonctionner avec Tab, fleches, Entree, Echap et Backspace, conserver les choix entre les etapes et en edition, puis stocker une valeur normalisee dans parcours_countries.

Le dropdown doit se fermer au clic exterieur et avec Echap. Les tags ne doivent pas etre tronques. Le focus, le contraste, les erreurs, le chargement et le responsive doivent etre professionnels. La meme structure doit etre respectee dans la fiche, l'edition, l'import et l'export.

## 5. Import, export et statistiques

Tester un fichier reel : detection des colonnes, validation des lignes, creation, mise a jour, rapport des erreurs, detection Port/Corridor/Aeroport, parcours multi-pays, doublons, transactions et echec partiel.

Tester les statistiques et cartes : filtres dates, marches et types, etat vide, KPI, tableaux, graphiques, volumes par pays et cache. Verifier que les anciennes donnees avec type NULL ne sont pas incorrectement classees et que les requetes utilisent la syntaxe de la base reelle, MariaDB ou PostgreSQL.

## 6. Transmissions et cloture

Tester creation, destinataire, statut, lecture, accuse de reception, modification, suppression, cloture et droits.

Le bouton de cloture doit etre clairement visible dans chaque ligne ou dans la fiche, avec libelle explicite, icone coherente, confirmation si necessaire, changement immediat du statut, date et auteur, message de succes, blocage d'une double cloture et controle serveur. Si le tableau glisse horizontalement, garder les actions essentielles visibles et verifier l'usage mobile.

## 7. Compte et securite

Tester connexion, deconnexion, mot de passe oublie, changement de mot de passe, ancien mot de passe incorrect, confirmation, erreurs, expiration de session, roles, acces direct interdit, CSRF et absence de secrets dans le HTML ou les logs.

## 8. Audit visuel et UX

Pour chaque page, verifier hierarchie, espacements, couleurs, contraste, texte, hover, focus, disabled, loading, boutons, tableaux, texte coupe, icones, feedback et responsive. Utiliser l'arbre d'accessibilite, le DOM, les requetes et les tests pour les problemes fonctionnels, et les captures pour les problemes visuels.

## 9. Methode de livraison

Classer les anomalies : Bloquant, Critique, Majeur ou Mineur. Traiter dans cet ordre : erreurs serveur et SQL, CRUD et liens, insertion et droits, import/export et statistiques, parcours multi-pays, transmissions, puis design et accessibilite.

Pour chaque lot, livrer les fichiers modifies, la cause racine, la correction, les tests executes, le resultat navigateur, les migrations executees et les risques restants. Ne jamais conclure par « cela devrait fonctionner » : conclure uniquement apres une verification executable et observable.
