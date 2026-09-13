# AUDIT DE SÉCURITÉ — PLATEFORME GEI
## Rapport : Rôles, Mots de Passe et Chiffrement des Données
**Date** : 2026-09-04  
**Plateforme** : Symfony 7.3 / GEI Platform  
**Auditeur** : Expert sécurité SI (35 ans d'expérience)

---

## PARTIE A — FAILLES EXPOSÉES PAR LES RÔLES DANS LE CODE

### A.1 — Exposition côté client

**Aucune logique de rôle en JavaScript** — Conforme.  
Les fichiers JS (`assets/app.js`, `assets/controllers/csrf_protection_controller.js`, `assets/controllers/hello_controller.js`, `assets/stimulus_bootstrap.js`) ne contiennent aucune vérification de rôle côté client. Aucune logique conditionnelle basée sur un rôle n'est présente dans le code JavaScript.

**Aucun champ caché transmettant le rôle** — Conforme.  
Aucun `<input type="hidden" name="role">` ou paramètre d'URL transmettant le rôle n'a été trouvé. Le rôle est toujours lu depuis `$this->getUser()->getRole()` côté serveur.

**Pas de token JWT exploitable** — Conforme partiellement.  
Le bundle `lexik/jwt-authentication-bundle` est installé et configuré (`config/packages/lexik_jwt_authentication.yaml:1`) avec des variables d'environnement pour la clé (`%env(resolve:JWT_SECRET_KEY)%`, `%env(JWT_PASSPHRASE)%`). Cependant :
- Les clés PEM (`config/jwt/private.pem`, `config/jwt/public.pem`) **n'existent pas** sur le dépôt.
- Aucune ressource API Platform n'utilise l'annotation `#[ApiResource]`. Aucune entité n'est exposée en tant que ressource API.
- Le bundle JWT est configuré mais **inutilisé** — il n'y a aucun endpoint API authentifié par JWT dans le code.

### A.2 — Exposition dans les templates

**Rôle transmis en clair dans le HTML** — **VU** :  
- `templates/partials/_sidebar.html.twig:207` — `{{ app.user.role.label() }}` affiche le libellé du rôle de l'utilisateur connecté. Ce n'est pas un risque de sécurité car l'utilisateur connaît déjà son propre rôle, mais c'est une exposition inutile d'informations internes.
- `templates/admin/users.html.twig:97` — `u.role.value` (la constante `ROLE_SUPERADMIN`, etc.) est utilisée comme nom de classe CSS. Visible uniquement par les SUPERADMIN.
- `templates/admin/activites.html.twig:128,143` — `log.user.role.value` expose la constante de rôle brute dans le journal d'activité. Visible par les PFT+.

**Données cachées en CSS `display:none` / `hidden`** — **Conforme** :  
Le grep `display:none|d-none|hidden` dans `templates/` a trouvé 96 occurrences, mais **aucune ne cache de données sensibles**. Les classes `hidden`/`d-none`/`display:none` sont utilisées exclusivement pour :
- Des modaux affichés via JavaScript (ex: `logDetailModal` dans `admin/activites.html.twig:269`) — ces modaux sont peu peuplés via JS à partir d'attributs `data-*` du DOM, **pas** via des données pré-chargées dans le HTML.
- Des toggles de sous-menus (`sidebar-submenu` dans `partials/_sidebar.html.twig:47,84,126,170`).
- Des tabs d'affichage conditionnel (`vuetexte`, `vuetableau`, `vuegraphiques`, `vuecarte` dans `audit/index.html.twig`).
- Aucune donnée sensible n'est chargée dans le contrôleur puis masquée en CSS.

**Commentaires de code / routes de debug exposées** — **Conforme** :  
- Le WebProfiler et le WDT ne sont activés que dans `when@dev` (`config/routes/web_profiler.yaml:1`, `config/packages/web_profiler.yaml:1`).
- Aucune route `/debug`, `/test`, `/_profiler` n'est disponible en production.
- Le `.env` n'est pas directement accessible via une URL (les fichiers `.env*` ne sont pas dans `/public/`).

### A.3 — Vérification structurelle du contrôle d'accès

**Voters centralisés** — **Conforme** :  
Trois Voters portent toute la logique d'autorisation :
- `src/Voter/AlertVoter.php` — Vue, édition, qualification, décision, transmission, rejet, export d'alertes.
- `src/Voter/SourceVoter.php` — Accès à l'identité brute de la source (requiert SAHOLTY/SUPERADMIN + 2FA activé).
- `src/Security/AuditVoter.php` — Accès aux pages audit (vue/export).

**Contrôles inline dans les contrôleurs** — **VU mineur** :  
Des vérifications de rôle en ligne existent dans :
- `src/Controller/AuditController.php:97` — `if ($user->getRole() !== UserRoleEnum::EMETTEUR_TERRAIN)` — pour charger ou non la liste des agents.
- `src/Controller/AuditController.php:102` — `if ($user->getRole() === UserRoleEnum::SUPERADMIN)` — pour charger ou non la liste des managers.
- `src/Controller/Dashboard/StatistiquesController.php:45` — `if ($user->getRole() === UserRoleEnum::PFT && $marketIds === [])` — pour restreindre les marchés.

Ces contrôles sont utilisés pour **filtrer les données chargées** (optimisation de présentation), pas pour l'autorisation elle-même. L'autorisation est assurée par les `#[IsGranted]` et les Voters. Cependant, la présence de ces vérifications dispersées augmente le risque d'oubri du contrôle d'accès lors d'évolutions futures.

**Hiérarchie des rôles** — **VU incohérence** :  
Une **incohérence** existe entre la hiérarchie définie dans `security.yaml` et la méthode `symfonyRoles()` de l'enum :

| Rôle | `security.yaml` role_hierarchy | `UserRoleEnum::symfonyRoles()` |
|------|-------------------------------|-------------------------------|
| SUPERADMIN | ROLE_SAHOLTY, ROLE_SECRETARIAT_GEI, ROLE_COMITE_AIT, ROLE_ALLOWED_TO_SWITCH | ROLE_SAHOLTY, ROLE_PFT, ROLE_USER (MANQUE ROLE_SECRETARIAT_GEI et ROLE_COMITE_AIT) |

La méthode `symfonyRoles()` pour `SUPERADMIN` retourne `['ROLE_SUPERADMIN', 'ROLE_SAHOLTY', 'ROLE_PFT', 'ROLE_USER']` mais **n'inclut pas** `ROLE_SECRETARIAT_GEI` et `ROLE_COMITE_AIT`. Ces rôles sont ajoutés par la hiérarchie dans `security.yaml`. Bien que les Voters utilisent l'enum directement (contournant la hiérarchie), cette incohérence est fragile : si la hiérarchie `role_hierarchy` était modifiée, le SUPERADMIN perdrait l'accès aux routes `SECRETARIAT_GEI` et `COMITE_AIT`.

**Gestion des marchés pour le Manager (PFT)** — **Conforme** :  
- `UserRepository::findByRoleAndMarket()` (ligne 54) interroge la table `manager_marches` en temps réel à chaque appel.
- `User::getAllManagedMarkets()` (ligne 273) combine les marchés gérés (collection `markets`) et le marché principal.
- La vérification se fait en base de données, **pas** en session. Un retrait de marché à un Manager prend effet immédiatement (pas de cache de session).
- Le Voter `AlertVoter` utilise `$user->getAllManagedMarkets()` (ligne 83, 99, 121, 134, 144) pour chaque décision d'accès.

---

## PARTIE B — MOTS DE PASSE : HACHAGE ET POLITIQUE

### B.1 — Algorithme de hachage

**Configuration** : `security.yaml:3` utilise `algorithm: auto` pour `PasswordAuthenticatedUserInterface`.  
**Résultat** : Sur le serveur test (PHP 8.2.12), **l'extension `argon2` n'est PAS installée**. L'algorithme `auto` de Symfony résout donc vers **bcrypt** (`$2y$`), pas argon2id.

Le test de vérification (`test_password.php:4`) confirme l'utilisation de bcrypt :
```php
$hash = '$2y$13$0T9.kEqjyQpzU01f2oreYeEZbvU6wHKfKgwWK7.Ez9gdQg8ROjmpO';
```
Le préfixe `$2y$` indique bcrypt. Bcrypt est secure mais **moins résistant qu'argon2id** contre les attaques GPU/ASIC.

**Recommandation** : Installer l'extension PHP `ext-argon2i` ou `ext-argon2id` et vérifier que `password_hashers` utilise `auto` (Symfony choisira argon2id automatiquement).

**Aucun MD5/SHA1 pour le hachage des mots de passe** — **Conforme** :  
Le grep `md5(|sha1(` dans `src/` trouve 23 occurrences, mais **toutes sont utilisées pour la génération de clés de cache**, pas pour le hachage des mots de passe :
- `src/Service/StatistiquesService.php` — clés de cache Redis (`'stat_volume_pays_' . md5(...)`)
- `src/Service/KPIService.php` — clés de cache (`'kpi_alertes_soumises_' . md5(...)`)
- `src/Controller/Alert/ImportController.php:54` — `$cache->delete('kpi_alertes_soumises_' . md5(''))`
- `src/Dto/AuditFilterDTO.php:126` — génération de clé de cache

**Aucun sel manuel** — **Conforme** :  
Aucune gestion manuelle de sel (`salt`) n'est présente. Les algorithmes modernes (bcrypt via Symfony `UserPasswordHasherInterface`) intègrent automatiquement la gestion du sel.

### B.2 — Politique de mot de passe

**Complexité minimale** — **Conforme partiellement** :
- Registration (`RegisterController.php:65`) : 12 caractères minimum + majuscule + minuscule + chiffre.
- Changement de mot de passe (`AccountController.php:55`) : même règle.
- **Mais** : Aucune vérification que le mot de passe n'est pas identique à l'email ou au nom d'utilisateur.

**Formulaire admin UserType** — **Défaut critique** :  
`src/Form/UserType.php:59-64` : le champ `plainPassword` est de type `PasswordType` avec **aucune contrainte de validation** (`Assert`). Un SUPERADMIN peut créer un utilisateur avec un mot de passe d'un seul caractère ou vide. Le formulaire `UserType` n'a pas de `NotBlank` ou de contrainte de longueur minimale.

**Mécanisme de réinitialisation** — **Absent** :  
**Aucun mécanisme de réinitialisation de mot de passe n'existe** :
- Aucun contrôleur, route, ou service pour la réinitialisation.
- Le lien "Mot de passe oublié ?" dans `templates/auth/login.html.twig:53` est **commenté**.
- Aucune entité pour stocker les tokens de réinitialisation.
- Aucun email de réinitialisation n'est envoyé.

### B.3 — Cas particulier : secret 2FA

**CRITIQUE — Le secret TOTP est stocké en CLAIR** :

Dans `src/Entity/User.php:70-71` :
```php
#[ORM\Column(type: 'string', length: 255, nullable: true)]
private ?string $totpSecret = null;
```

Le champ `totpSecret` est stocké comme une **chaîne de caractères en clair** dans la base de données. **Aucun chiffrement n'est appliqué**. Le prompt exige explicitement que ce secret soit **chiffré** (pas haché) avec une clé stockée hors de la base de données.

**Conséquences** : Si un attaquant obtient un accès à la base de données (via SQL injection, dump, ou sauvegarde volée), il peut récupérer tous les secrets TOTP et générer des codes 2FA valides, complètement contournant l'authentification à deux facteurs.

**2FA non appliqué côté serveur** :  
- `config/packages/scheb_2fa.yaml` configure uniquement les tokens de sécurité (`UsernamePasswordToken`, `PostAuthenticationToken`) mais **n'impose pas** 2FA pour aucun rôle. Il n'y a pas de configuration `trusted_computing_bases`, pas de `ga` (Google Authenticator), pas de `auth_code_provider`.
- Le `SourceVoter` vérifie `$user->isTotpEnabled()` (ligne 28), mais 2FA n'est jamais **exigé** pour se connecter. Un utilisateur peut se connecter sans jamais activer 2FA.
- Le bundle `scheb/2fa-totp` est installé mais n'a **aucune configuration** (pas de `server_name`, pas de `parameter_provider`).

### B.4 — Invalidation des sessions après changement de mot de passe

**CRITIQUE — Les sessions ne sont pas invalidées** :

Dans `AccountController.php:60-61`, le changement de mot de passe :
```php
$user->setPassword($hasher->hashPassword($user, $new));
$em->flush();
```
**N'invalidate pas** les sessions existantes. Si un attaquant a obtenu un token de session valide, le changement de mot de passe ne le déconnectera pas. L'attaquant conservera l'accès jusqu'à expiration de la session.

---

## PARTIE C — CHIFFREMENT DES DONNÉES SENSIBLES AU REPOS

### C.1 — Identité réelle des sources

**ABSENCE — Aucun chiffrement car aucune donnée sensible chiffrée** :

L'entité `src/Entity/Source.php` ne possède **aucun champ** `source_identity` ou équivalent. Le commentaire en ligne 9-11 indique :
> *« L'identité réelle, si nécessaire, est dans source_identity chiffrée (hors scope initial). »*

Mais **aucune implémentation n'existe**. La table `source` ne contient que : `codeAnonyme`, `fiabilite`, `historique`, `pays`, `creeLe`.

**Données d'identité exposées en clair dans d'autres tables** :
- `src/Entity/AlertActor.php:24` — `nomOuRaisonSociale` stocké en clair (colonne `string`, 255 caractères).
- `src/Entity/Alert.php:103` — `emetteurTexte` stocké en clair (texte libre de l'émetteur).
- `src/Entity/User.php` — `prenom`, `nom`, `email`, `telephone`, `fonction` tous en clair.

**Aucune bibliothèque de chiffrement** n'est présente : grep de `sodium_crypto_secretbox|openssl_encrypt|aes-256|sodium|encrypt|decrypt` dans `src/` → **aucun résultat**. Aucun bundle comme `ambta/doctrine-encrypted-value-bundle` n'est installé.

### C.2 — Historique Git — fuites de secrets

**CRITIQUE — Clé JWT et mot de passe compromises committés** :

Recherche `git log -p -- .env` a trouvé :
1. **`JWT_PASSPHRASE=87dc7af4d93cdf1cac1323795834c900209de33e9e5f221ee28265e2a8045a14`** — **committé depuis le premier commit** (`010619c feat: Plateforme GEI complete...`) et persisté dans l'historique Git. Cette passphrase est utilisée pour chiffrer/déchiffrer la clé JWT privée RSA.
2. **`test_password.php`** — committé (`010619c`) avec le mot de passe en clair `Admin@2026!` et un hash bcrypt `$2y$13$0T9.kEqjyQpzU01f2oreYeEZbvU6wHKfKgwWK7.Ez9gdQg8ROjmpO`.
3. **`APP_SECRET=` est vide** dans `.env` (commis depuis le début). Seules les versions dev (`APP_SECRET=dcc40c443e87fc5a2674794ed30e714e`) et test (`APP_SECRET='$ecretf0rt3st'`) ont une valeur.

**Le fichier `.env` n'est PAS dans `.gitignore`** :
`.gitignore` liste `/.env.local` et `/.env.*.local` mais **pas** `/.env`. Le fichier `.env` versionné contient le passphrase JWT.

**Action requise** : La JWT passphrase `87dc7af4d93cdf1cac1323795834c900209de33e9e5f221ee28265e2a8045a14` doit être considérée comme **compromise**. Elle doit être **régénérée** et la nouvelle valeur placée dans `.env.local` (gitignoré) ou une variable d'environnement. Le hash bcrypt correspondant à `Admin@2026!` doit être **révoqué/changé**.

### C.3 — Chiffrement en transit

**HTTPS / HSTS** :
- `SecurityHeadersSubscriber.php:32-34` : `Strict-Transport-Security` est positionné avec `max-age=31536000; includeSubDomains` **uniquement si** `$request->isSecure()` est vrai. En production derrière un load balancer, la requête peut être HTTP côté Symfony (même si le client utilise HTTPS).
- **Bug** : L'en-tête est uniquement appliqué pour les réponses 2xx, 403, 404 (ligne 23) :
  ```php
  if (!$response->isSuccessful() && $response->getStatusCode() !== 403 && $response->getStatusCode() !== 404) {
      return; // Ne pas définir les en-têtes pour les 3xx, 5xx
  }
  ```
  Les réponses de redirection (3xx) et les erreurs serveur (5xx) **n'ont pas** les en-têtes de sécurité.

**Cookies de session** :
- `cookie_secure: auto` — conforme (cookies uniquement via HTTPS).
- `cookie_samesite: lax` — conforme (protection CSRF).
- `cookie_httponly` — **non explicitement défini** dans `framework.yaml`. Symfony le définit à `true` par défaut, mais **il devrait être explicite** pour être sûr.

**Cookie consentement géolocalisation** (login template) :
- `templates/auth/login.html.twig:132-134` : Le cookie `geo_consent` est défini via JavaScript sans l'attribut `Secure` :
  ```javascript
  document.cookie = 'geo_consent=granted; max-age=31536000; path=/; SameSite=Lax';
  ```
  **Manque l'attribut `Secure`**. Ce cookie peut être transmis en HTTP.

### C.4 — Gestion des secrets applicatifs

**Monolog** : Configuration standard, pas de logging de mots de passe/tokens. Les logs d'audit (`AccessLog` entity) enregistrent les IP, actions, mais pas les mots de passe. Conforme.

**API Platform** : `stateless: true` mais aucune ressource exposée. Conforme mais inutile.

---

## PARTIE D — AUTRES POINTS DE DURCISSEMENT

### D.1 — Audit des dépendances

**`composer audit`** — **18 vulnérabilités** affectant 11 paquets :

| Sévérité | Nombre | CVE | Package |
|----------|--------|-----|---------|
| **CRITIQUE/Haute** | 3 | CVE-2026-45067 | symfony/mime — Injection SMTP/CRLF |
| | | CVE-2026-45077 | symfony/monolog-bridge — Désérialisation PHP non authentifiée |
| | | CVE-2026-48489 | symfony/security-http — Contournement du firewall via failure_forward |
| **Moyenne** | 5 | CVE-2026-45073 | symfony/cache — Injection SQL |
| | | CVE-2026-48736 | symfony/http-foundation — SSRF bypass IPv6 |
| | | CVE-2026-45068 | symfony/mailer — Injection d'argument |
| | | CVE-2026-45070 | symfony/mime — Injection header email |
| | | CVE-2026-48784 | symfony/routing — Problème d'encodage URL |
| | | CVE-2026-45065 | symfony/routing — Contournement de contrainte de route |
| | | CVE-2026-47767 | symfony/runtime — APP_ENV/APP_DEBUG bypass |
| | | CVE-2026-46626 | symfony/runtime — APP_ENV/APP_DEBUG bypass |
| | | CVE-2026-45074 | symfony/security-http — Replay ticket CAS |
| **Basse** | 5 | CVE-2026-45071 | symfony/dom-crawler — XXE |
| | | CVE-2026-45072 | symfony/web-profiler-bundle — XSS stockée |
| | | CVE-2026-45304 | symfony/yaml — Allocation mémoire excessive |
| | | CVE-2026-45305 | symfony/yaml — ReDoS |
| | | CVE-2026-45133 | symfony/yaml — Épuisement pile |

**⚠️ La CVE-2026-48489 (sécurité-http)** est particulièrement critique : elle permet un contournement du firewall d'accès non authentifiée sur les routes GET protégées.

### D.2 — Verrouillage de compte après tentatives échouées

**Partiellement conforme** :  
- `security.yaml:30-32` configure `login_throttling` : 5 tentatives max / 15 minutes.
- **Mais** : c'est du *rate limiting*, pas un verrouillage de compte. Après 5 échecs, l'attaquant attend 15 minutes et réessaite.
- **Aucun verrouillage de compte temporaire** n'existe (aucun flag `locked`, `failed_attempts`, `lockedUntil`).
- **Aucune notification** à l'utilisateur en cas de tentative de connexion suspecte.

### D.3 — Sauvegardes chiffrées

**ABSENCE** : Aucune configuration de sauvegarde n'est présente dans le dépôt. Aucun script, aucune référence à `mysqldump`, `pg_dump`, ou service de sauvegarde. Impossible de vérifier l'état des sauvegardes.

### D.4 — Principe du moindre privilège sur la base de données

**Non vérifiable depuis le code** :  
- `DATABASE_URL="mysql://app:!ChangeMe!@127.0.0.1:3306/gei_platform..."` — l'utilisateur `app` est utilisé.
- Impossible de déterminer ses privilèges depuis le dépôt. Vérifier au niveau MySQL que l'utilisateur `app` n'a que `SELECT`, `INSERT`, `UPDATE`, `DELETE` sur les tables applicatives (pas de `DROP`, `CREATE`, `ALTER`, `SUPER`, `FILE`).

### D.5 — Séparation des environnements

**Conforme partiellement** :
- `.env.dev` : `APP_SECRET=dcc40c443e87fc5a2674794ed30e714e` (dev uniquement).
- `.env.test` : `APP_SECRET='$ecretf0rt3st'` (test uniquement).
- `.env` : `APP_SECRET=` (vide — valeur attendue dans `.env.local`).
- Aucune donnée réelle de production n'est visible dans les environnements de test/dev.
- La base de données SQLite locale (`gei_platform.db` dans `var/`) contient des données factices selon le `.gitignore` et la structure.

### D.6 — Exposition des données sensibles dans le dashboard

**VU** : Le `OperationnelController` (`src/Controller/Dashboard/OperationnelController.php`) charge des **KPI globaux non filtrés par marché** :
- `$kpisBase = $alertRepository->getKpiStats()` — compte TOTAL toutes les alertes (pas de filtre market).
- `$kpisByPays = $alertRepository->countByPays()` — toutes les données par pays.
- `$kpisByPrio = $alertRepository->countByNiveauPriorite()` — toutes les données.
- `$activeCases = $urgenceRepo->findActiveCases()` — toutes les cases d'urgence actives (pas de filtre market).

Un utilisateur `EMETTEUR_TERRAIN` (AGENT) voit donc **toutes les alertes de toutes les marchés** dans les indicateurs KPI, même s'il ne peut voir que ses propres alertes dans la liste (`findForUser` filtre correctement). La liste d'alertes (`alerts`) est correctement filtrée par `findForUser()`.

---

## RÉCAPITULATIF DES FAILLES ET CORRECTIONS

### 🔴 CRITIQUE — À corriger impémentiellement (nécessite une action client)

| # | Faille | Fichier | Action requise |
|---|--------|---------|----------------|
| 1 | **Secret TOTP stocké en clair** | `src/Entity/User.php:71` | Chiffrer le champ `totpSecret` avec `sodium_crypto_secretbox` ou `openssl_encrypt` en utilisant une clé d'environnement. Régénérer tous les secrets existants. |
| 2 | **Mot de passe `Admin@2026!` en clair** | `test_password.php` | Supprimer le fichier. Révoquer/Changer le mot de passe associé. Considérer la clé JWT comme compromise. |
| 3 | **Passphrase JWT committée** | `.env:55` | Régénérer la passphrase JWT. Placer la nouvelle valeur dans `.env.local` (gitignoré). Retourner `.env` dans `.gitignore`. |
| 4 | **APP_SECRET vide dans .env** | `.env:19` | Définir une valeur forte dans `.env.local` ou variable d'environnement. |
| 5 | **CVE-2026-48489** (firewall bypass) | dépendances Symfony | Mettre à jour `symfony/security-http` et toutes les dépendances Symfony vers les versions corrigées. |

### 🟠 ÉLEVÉE — À corriger rapidement

| # | Faille | Fichier | Action |
|---|--------|---------|--------|
| 6 | **Aucun verrouillage de compte** | `security.yaml:30` | Implémenter un système de verrouillage temporaire après N tentatives (ex: 5 tentatives → 15 min de verrouillage). |
| 7 | **Aucune réinitialisation de mot de passe** | - | Implémenter un flux complet : token à usage unique, durée de vie 1h, envoi par email, invalide après usage. |
| 8 | **Pas d'invalidation de session après changement de MDP** | `AccountController.php:60` | Appeler `$request->getSession()` et invalider la session après changement de mot de passe. |
| 9 | **2FA non imposé** | `config/packages/scheb_2fa.yaml` | Configurer le 2FA comme obligatoire pour les rôles `SAHOLTY` et `SUPERADMIN` via la configuration du bundle. |
| 10 | **Admin peut créer des comptes avec mots de passe faibles** | `src/Form/UserType.php:59` | Ajouter des contraintes `Assert\NotBlank` et `Assert\Length(min:12)` sur le champ `plainPassword`. |
| 11 | **Cookie geo_consent sans attribut Secure** | `templates/auth/login.html.twig:132` | Ajouter `; Secure` au cookie via JavaScript. |
| 12 | **KPI globaux non filtrés par marché** | `OperationnelController.php:51` | Filtrer les KPI par le marché du Manager (ou par tous les marchés pour SUPERADMIN/SAHOLTY). |
| 13 | **Headers de sécurité pas sur toutes les réponses** | `SecurityHeadersSubscriber.php:23` | Appliquer les headers sur toutes les réponses, pas seulement 2xx/403/404. |
| 14 | **Aucune clé de chiffrement pour les identités** | `src/Entity/Source.php:9-11` | Implémenter le chiffrement AES-256 pour les identités sources sensibles (via `sodium_crypto_secretbox` ou `ambta/doctrine-encrypted-value-bundle`). |
| 15 | **argon2id non disponible** | serveur PHP | Installer `ext-argon2` pour profiter de hachage plus résistant. |

### 🟡 MOYENNE — À corriger

| # | Faille | Fichier | Action |
|---|--------|---------|--------|
| 16 | **Incohérence role_hierarchy vs enum** | `UserRoleEnum.php:51` | Synchroniser `symfonyRoles()` pour inclure `ROLE_SECRETARIAT_GEI` et `ROLE_COMITE_AIT` pour SUPERADMIN. |
| 17 | **Contrôles de rôle inline dans les contrôleurs** | `AuditController.php:97,102` | Déplacer la logique de chargement de données dans des services ou des Voters pour centraliser. |
| 18 | **Cookie httponly non explicite** | `framework.yaml:6` | Ajouter `cookie_httponly: true` explicitement. |
| 19 | **Pas de CSP** | `SecurityHeadersSubscriber.php` | Ajouter l'en-tête `Content-Security-Policy`. |
| 20 | **Pas de Content-Security-Policy** | - | Ajouter un CSP restrictive. |

### 🟢 CONTRÔLES — Conformes

| # | Élément vérifié | Conclusion |
|---|---------|------------|
| 1 | Aucune logique de rôle en JavaScript | ✓ Conforme |
| 2 | Aucun `<input type="hidden" name="role">` | ✓ Conforme |
| 3 | MD5/SHA1 non utilisé pour le hachage des mots de passe | ✓ Conforme (md5 utilisé uniquement pour les clés de cache) |
| 4 | Aucun sel manuel | ✓ Conforme |
| 5 | Voters centralisés (AlertVoter, SourceVoter, AuditVoter) | ✓ Conforme |
| 6 | Filtrage des données au niveau SQL (AlertRepository::findForUser) | ✓ Conforme |
| 7 | Vérification du marché en base à chaque contrôle d'accès | ✓ Conforme |
| 8 | WebProfiler/WDT désactivés en prod | ✓ Conforme |
| 9 | HTTPS/HSTS configuré (avec caveat sur isSecure) | ✓ Partiellement conforme |
| 10 | cookie_secure: auto, cookie_samesite: lax | ✓ Conforme |
| 11 | Politique de mot de passe (12+ car, maj, min, chiffre) sur registration | ✓ Conforme |
| 12 | CSRF tokens sur tous les formulaires | ✓ Conforme |
| 13 | login_throttling configuré (5 tentatives / 15 min) | ✓ Partiellement conforme |

---

## NOTES SUR LES CORRECTIONS

**JWT_PASSPHRASE compromise** : La passphrase JWT `87dc7af4d93cdf1cac1323795834c900209de33e9e5f221ee28265e2a8045a14` est présente dans l'historique Git depuis le premier commit. Elle doit être **régénérée immédiatement**. La nouvelle clé doit être générée (`openssl genrsa -aes256 -out config/jwt/private.pem -passout pass:NEW_PASSPHRASE 4096`) et la passphrase doit être stockée dans `.env.local` ou une variable d'environnement. **Action client requise** : régénération de la clé et invalidation de tous les tokens JWT existants.

**test_password.php** : Supprimer ce fichier du dépôt et de l'historique Git (`git filter-branch` ou `BFG Repo-Cleaner`). Le mot de passe `Admin@2026!` doit être **changé immédiatement** sur tous les systèmes où il pourrait être utilisé.

**totpSecret en clair** : Pour corriger, chiffrer le champ `totpSecret` à l'aide de `sodium_crypto_secretbox` avec une clé stockée dans une variable d'environnement (`ENCRYPTION_KEY`). Tous les secrets TOTP existants doivent être **régénérés** car ils ont été exposés en clair. Utiliser un type Doctrine personnalisé ou le bundle `ambta/doctrine-encrypted-value-bundle`.

**CVE-2026-48489 (firewall bypass)** : Mettre à jour `symfony/security-http` vers une version corrigée (≥7.4.12 ou le patch équivalent). Cette vulnérabilité permet un contournement du firewall d'accès non authentifiée sur les routes GET — critique car elle contourne toute la couche `access_control`.
