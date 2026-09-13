# Documentation Technique — Plateforme GEI

> **État du code** : audit complet au 12/09/2026. Stack, déploiement, variables d'env, sécurité, base de données, migrations.

---

## 1. Stack Technique

| Composant | Version | Rôle |
|-----------|---------|------|
| **PHP** | ≥ 8.2 | Runtime |
| **Symfony** | 7.3.* (LTS) | Framework full-stack |
| **Doctrine ORM** | 3.6 | Mapping objet-relationnel (attributes PHP 8) |
| **Doctrine Migrations** | 3.7 | Versioning schema DB |
| **Base de données** | **MariaDB 10.4** (dev via `DATABASE_URL`) / **PostgreSQL 16** (Docker Compose prod) | Stockage principal |
| **Messenger** | 7.3 | Bus de messages (transport `doctrine://` par défaut) |
| **Workflow** | 7.3 | Machine à états `alert_lifecycle` |
| **Security** | 7.3 | Auth form + JWT (Lexik) + TOTP 2FA (Scheb) |
| **Validation** | Symfony Validator + Assert annotations | Contraintes entités/formulaires |
| **Twig** | 3.x | Templates serveur |
| **Asset Mapper** | 7.3 | Gestion assets (JS/CSS) sans Node |
| **Stimulus + UX Turbo** | 2.36 | Interactivité légère (SPA-like) |
| **PhpSpreadsheet** | 5.9 | Import/Export Excel (.xlsx) |
| **DomPDF** | 3.1 | Export PDF |
| **PHPStan** | 2.2 | Analyse statique (config `phpstan.dist.neon`) |
| **PHPUnit** | 11.5 | Tests unitaires / intégration |
| **PHP-CS-Fixer** | 3.95 | Style code (config `.php-cs-fixer.dist.php`) |

### Dépendances clés `composer.json` (extraits)
```json
{
  "require": {
    "php": ">=8.2",
    "symfony/framework-bundle": "7.3.*",
    "doctrine/orm": "^3.6",
    "lexik/jwt-authentication-bundle": "^3.2",
    "scheb/2fa-bundle": "^7.14",
    "scheb/2fa-totp": "^7.14",
    "phpoffice/phpspreadsheet": "^5.9",
    "dompdf/dompdf": "^3.1",
    "symfony/workflow": "7.3.*",
    "symfony/messenger": "7.3.*"
  }
}
```

---

## 2. Architecture Base de Données

### Tables principales (extrait migrations)

| Table | Entité | Description |
|-------|--------|-------------|
| `alert` | `Alert` | Table centrale — 50+ colonnes, index composites |
| `user` | `User` | Utilisateurs + rôles + TOTP + marchés gérés/assignés |
| `market` | `Market` | Marchés/pays (ISO3, nom, actif) |
| `alert_transmission` | `AlertTransmission` | Transmissions externes + double validation |
| `urgence_72h_case` | `Urgence72hCase` | Cas urgence 72h (1:1 avec Alert) |
| `urgence_72h_phase` | `Urgence72hPhase` | 3 phases par cas (SLA T0→T+72h) |
| `alert_qualification_history` | `AlertQualificationHistory` | Historique immuable scoring (snapshot) |
| `alert_status_history` | `AlertStatusHistory` | Historique changements de statut |
| `alert_comment` | `AlertComment` | Commentaires historisés Agent/Manager |
| `alert_attachment` | `AlertAttachment` | Pièces jointes (entité prête, UI absente) |
| `alert_actor` | `AlertActor` | Acteurs liés à l'alerte (entité prête, UI absente) |
| `notification` | `Notification` | Notifications in-app + email |
| `access_log` | `AccessLog` | Journal d'audit complet (action, user, IP, diff) |
| `rule_config` | `RuleConfig` | Seuils configurables (score_critique, etc.) |
| `liste_reference_valeur` | `ListeReferenceValeur` | Listes administrables (catégories, types source, etc.) |
| `manager_marches` | (ManyToMany) | Marchés gérés par Manager (PFT) |
| `agent_marches` | (ManyToMany) | Marchés assignés à Agent (créés par Manager) |
| `connection_position` | `ConnectionPosition` | Géolocalisation connexions (subscriber) |
| `zone_geo` | `ZoneGeo` | Coordonnées géographiques & polygones WKT des marchés pour la cartographie Leaflet |

### Index critiques (`Alert` entity)
```php
idx_alert_statut              (statut)
idx_alert_market              (market_id)
idx_alert_emetteur            (emetteur_id)
idx_alert_date                (date_creation)
idx_alert_priorite            (niveau_priorite)
idx_alert_statut_market       (statut, market_id)
idx_alert_date_statut         (date_creation, statut)
```

### Soft delete
- `Alert.deletedAt` (nullable) — toutes les requêtes filtrent `WHERE deletedAt IS NULL` (Repository, AuditService).

---

## 3. Migrations Notables (chronologique)

| Fichier | Date | Description |
|---------|------|-------------|
| `Version20260817001200.php` | 17/08 | Schéma initial |
| `Version20260817120000.php` | 17/08 | Ajout champs scoring + workflow |
| `Version20260817130000.php` | 17/08 | Tables `rule_config`, `liste_reference_valeur` |
| `Version20260826000003.php` | 26/08 | `manager_marches` + `agent_marches` (N-N) |
| `Version20260827014500.php` | 27/08 | `AlertTransmission` + double validation |
| `Version20260831182407.php` | 31/08 | `Urgence72hCase` + `Urgence72hPhase` |
| `Version20260904000000.php` | 04/09 | Champs traçabilité Manager (Partie B) : `validatedBy`, `dateValidation` |
| `Version20260904000001.php` | 04/09 | Champs surcharge score : `scoreSurcharge`, `niveauPrioriteSurcharge`, `justificationSurcharge` |
| `Version20260908000001.php` | 08/09 | `AlertQualificationHistory` (snapshot immuable) |
| `Version20260908000004.php` | 08/09 | `AccessLog` + index |
| `Version20260912160000.php` | 12/09 | **`operateur_acteur` VARCHAR(255) nullable** — Positionnée en colonne AD (30e) export Excel |
| `Version20260912141034.php` | 12/09 | Correction index `alert_transmission` |
| `Version20260912000001.php` | 12/09 | Nettoyage colonnes redondantes (audit 17/08) |

> **Commande migration** : `php bin/console doctrine:migrations:migrate --no-interaction`

---

## 4. Déploiement & Infrastructure

### Docker Compose (`compose.yaml` + `compose.override.yaml`)
```yaml
services:
  database:
    image: postgres:16-alpine
    environment:
      POSTGRES_DB: ${POSTGRES_DB:-app}
      POSTGRES_USER: ${POSTGRES_USER:-app}
      POSTGRES_PASSWORD: ${POSTGRES_PASSWORD:-!ChangeMe!}
    volumes:
      - database_data:/var/lib/postgresql/data
    healthcheck:
      test: ["CMD", "pg_isready", "-d", "${POSTGRES_DB:-app}", "-U", "${POSTGRES_USER:-app}"]
```

### Variables d'environnement (`.env.dist` → `.env.local`)

| Variable | Obligatoire | Description | Défaut `.env.dist` |
|----------|-------------|-------------|-------------------|
| `APP_ENV` | Oui | Environnement (dev/prod/test) | `dev` |
| `APP_SECRET` | **Oui (prod)** | Secret kernel (CSRF, signatures) | `changeme_change_me_in_env_local` |
| `DATABASE_URL` | Oui | DSN Doctrine (MariaDB/PostgreSQL) | `mysql://app:!ChangeMe!@127.0.0.1:3306/gei_platform` |
| `MESSENGER_TRANSPORT_DSN` | Non | Transport queue (doctrine/redis/amqp) | `doctrine://default?auto_setup=0` |
| `MAILER_DSN` | Non | Envoi emails (null:// = dev) | `null://null` |
| `CORS_ALLOW_ORIGIN` | Non | Origines CORS autorisées | `^https?://(localhost\|127\.0\.0\.1)(:[0-9]+)?$` |
| `JWT_SECRET_KEY` | **Oui (prod)** | Chemin clé privée RSA 4096 | `%kernel.project_dir%/config/jwt/private.pem` |
| `JWT_PUBLIC_KEY` | **Oui (prod)** | Chemin clé publique | `%kernel.project_dir%/config/jwt/public.pem` |
| `JWT_PASSPHRASE` | **Oui (prod)** | Phrase de passe clé privée | `changeme_generate_new_jwt_keys` |
| `APP_ENCRYPTION_KEY` | **Oui (prod)** | Clé chiffrement données sensibles (TOTP) — **32 octets hex** | `changeme_generate_unique_key_for_encryption` |

> **Génération clés** :
> ```bash
> # JWT (une seule fois)
> openssl genrsa -aes256 -passout pass:PASSPHRASE -out config/jwt/private.pem 4096
> openssl rsa -pubout -in config/jwt/private.pem -passin pass:PASSPHRASE -out config/jwt/public.pem
> 
> # Encryption (séparée du JWT)
> php -r "echo bin2hex(random_bytes(32));"
> ```

### Commandes build/prod
```bash
# Install deps
composer install --no-dev --optimize-autoloader

# Compile .env pour prod (symfony/flex)
composer dump-env prod

# Cache & assets
php bin/console cache:clear --env=prod
php bin/console asset-map:compile --env=prod

# Migrations
php bin/console doctrine:migrations:migrate --env=prod --no-interaction

# JWT keys (si absent)
mkdir -p config/jwt
# ... commandes openssl ci-dessus

# Worker Messenger (async escalades/notifications)
php bin/console messenger:consume async -vv
```

### Cron recommandé (crontab)
```cron
# SLA Watch 72h — toutes les 15 min
*/15 * * * * cd /path/to/gei-platform && php bin/console app:sla:watch >> /var/log/gei-sla.log 2>&1

# Nettoyage sessions expirées (optionnel)
0 3 * * * cd /path/to/gei-platform && php bin/console cache:pool:clear cache.app >> /var/log/gei-cache.log 2>&1
```

---

## 5. Sécurité Implémentée

### Authentification
- **Formulaire** (login/password) + CSRF + remember_me (7 jours) + throttling (5 essais / 15 min).
- **JWT** (Lexik) pour API — clés RSA 4096, passphrase dans `.env.local`.
- **2FA TOTP** (Scheb) — obligatoire selon config `scheb_2fa.yaml` :
  - `totp_secret` stocké **chiffré** via `EncryptedType` (libsodium `secretbox`).
  - `totpEnabled` booléen par utilisateur.

### Mots de passe
- Hash **auto** (PHP `password_hash` — argon2id par défaut Symfony 7.3).
- Cost réduit en test (`cost: 4`, `time_cost: 3`, `memory_cost: 10`).

### Autorisations (Voters)
- `AlertVoter` : 8 attributs (`VIEW`, `EDIT_COLLECTE`, `EDIT_QUALIFICATION`, `DECIDE`, `EXPORT`, `QUALIFY`, `TRANSMIT`, `REJECT`).
- `UserVoter` : gestion utilisateurs (Manager ↔ Agents de ses marchés).
- **Hiérarchie rôles** (`security.yaml`) :
  ```
  ROLE_SUPERADMIN > ROLE_SAHOLTY > ROLE_PFT > ROLE_EMETTEUR_TERRAIN > ROLE_USER
  ROLE_SECRETARIAT_GEI > ROLE_USER
  ROLE_COMITE_AIT > ROLE_USER
  ```

### Chiffrement au repos (`EncryptedType` + `CryptoService`)
- Algorithme : **libsodium `crypto_secretbox`** (XSalsa20-Poly1305) — authentifié, nonce aléatoire 24 octets.
- Clé dérivée : `APP_ENCRYPTION_KEY` (32 octets hex) → `sodium_crypto_generichash` si longueur ≠ 32.
- Utilisé pour : `User.totpSecret` (colonne `encrypted_string`).

### En-têtes HTTP (`SecurityHeadersSubscriber`)
- `Content-Security-Policy`, `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy`.

### Audit & Traçabilité
- `AccessLog` : chaque action métier (création, modif, export, transmission, qualification, validation, rejet, surcharge, connexion).
- `AlertStatusHistory` : transitions workflow nominatives.
- `AlertQualificationHistory` : snapshots scoring immuables.
- `AlertComment` : commentaires typés (agent/manager/system).

---

## 6. Services Métier Clés (Architecture)

| Service | Rôle | Déclencheur |
|---------|------|-------------|
| `ScoreCalculatorService` | Calcul score GEI + seuils `rule_config` | `AlertScoreSubscriber::onFlush` + `QualificationController` + `WizardController` |
| `EscalationRuleEngine` | 7 règles d'escalade auto | `AlertScoreSubscriber::onFlush` (après calcul score) |
| `ImportService` | Import Excel registre (détection dynamique colonnes) | `ImportController` |
| `ExportService` | Export Excel registre (colonnes officielles) | `ExportController` |
| `AuditService` / `AlertAuditRepository` | Module audit (filtres, agrégations, portée rôles) | `AuditController` |
| `AlertCodeGeneratorService` | Génération `GEI-{ISO3}-{ANNEE}-{SEQ:003}` | `prePersist` + Wizard submit + Import |
| `NotificationService` | Notifications in-app + email (Twig) | Controllers + `AlertEscalationHandler` + `AlertScoreSubscriber` |
| `CryptoService` | Chiffrement symétrique (libsodium) | `EncryptedType` (DBAL) |

### Bus Messenger (Async)
- Messages : `AlertEscalationMessage`, `CheckSlaMessage`, `SlaReminderMessage`.
- Handler : `AlertEscalationHandler` (7 types), `CheckSlaHandler`, `SlaReminderHandler`.
- Transport par défaut : `doctrine://` (table `messenger_messages`).

---

## 7. API & Endpoints Principaux

| Route | Méthode | Contrôleur | Rôle requis | Description |
|-------|---------|------------|-------------|-------------|
| `/login` | GET/POST | `LoginController` | PUBLIC | Auth formulaire |
| `/2fa/*` | GET/POST | Scheb2FA | PUBLIC | Activation/validation TOTP |
| `/wizard/new` | GET/POST | `WizardController` | USER (Agent) | Création alerte 6 étapes |
| `/alert/{id}/qualify` | GET/POST | `QualificationController` | PFT (Manager) | Qualification + décision |
| `/alert/{id}/override-score` | POST | `QualificationController` | PFT/SAHOLTY | Surcharge manuelle score |
| `/alert/{id}/transmit` | GET/POST | `TransmissionController` | PFT | Enregistrement transmission |
| `/transmissions/suivi` | GET | `TransmissionController` | PFT | Liste suivi transmissions |
| `/export/registre` | GET | `ExportController` | USER | Export Excel (filtres + portée) |
| `/export/registre/pdf` | GET | `ExportController` | USER | Export PDF registre |
| `/alert/{id}/pdf` | GET | `ExportController` | USER (EXPORT) | Export PDF fiche unique |
| `/api/preview-score` | POST | `QualificationController` | USER | Calcul live score (JS widget) |
| `/urgence` | GET | `Urgence72hDashboardController` | PFT | Dashboard cas 72h actifs |
| `/audit/*` | GET | `AuditController` | PFT | Module audit + graphiques |
| `/admin/*` | GET/POST | `Admin/*Controller` | PFT/SUPERADMIN | Gestion marchés, users, règles, listes |

> **API Platform** : installé (`api-platform/symfony`, `api-platform/doctrine-orm`) mais **aucune ressource exposée** (`src/ApiResource/` vide).

---

## 8. Tests & Qualité

### Configuration
- `phpunit.dist.xml` : suite par défaut, `tests/` namespace `App\Tests`.
- `phpstan.dist.neon` : niveau max (8), analyse `src/`.
- `.php-cs-fixer.dist.php` : règles Symfony + PSR-12.

### Couverture actuelle
- Tests unitaires : `ScoreCalculatorService`, `ImportService` (mapping enums), `CryptoService`.
- Tests intégration : `AlertVoter`, `UserVoter`, `AlertRepository::findForUser`.
- **Manquants** : tests E2E (Cypress/Playwright), tests de charge, tests sécurité (pen-test).

### Commandes CI
```bash
# Style
php vendor/bin/php-cs-fixer fix --dry-run --diff

# Analyse statique
php vendor/bin/phpstan analyse --memory-limit=512M

# Tests
php vendor/bin/phpunit --testdox

# Couverture (si xdebug)
php vendor/bin/phpunit --coverage-html var/coverage
```

---

## 9. Points Techniques Critiques (Gotchas)

| Sujet | Détail |
|-------|--------|
| **Recalcul score auto** | `AlertScoreSubscriber::onFlush` + garde-fou `$inFlush` anti-récursion. Ne **jamais** appeler `$em->flush()` dans un listener `onFlush`. |
| **Enum Backed + Workflow** | `AlertStatut` est un `BackedEnum` (string). Le `MethodMarkingStore` cast automatiquement la valeur string → Enum lors des transitions. |
| **Surcharge score** | `getEffectiveScore()` / `getEffectiveNiveauPriorite()` lisent `scoreSurcharge` / `niveauPrioriteSurcharge` en priorité. |
| **Portée requêtes** | **Toujours** passer par `AlertRepository::findForUser($user, $filters)` — applique la sécurité selon rôle. |
| **Import Excel** | Détection colonnes **dynamique** (fragments) — ne pas coder en dur les positions (col A souvent vide). |
| **Export colonne AD** | `operateur_acteur` exporté en colonne 30 (AD) mais **position officielle non confirmée** — ne pas documenter comme figée. |
| **TOTP secret** | Stocké chiffré (`encrypted_string`). Si `APP_ENCRYPTION_KEY` change → **perte d'accès 2FA** pour tous les utilisateurs. |
| **Soft delete** | `Alert.deletedAt` — toutes les requêtes Repository/Audit ajoutent `AND deletedAt IS NULL`. |
| `RuleConfig` cache | `ScoreCalculatorService::getSeuils()` cache par requête HTTP. Modification en base → effet au rechargement. |
| **Fonds de carte Leaflet** | Cartographie 100% libre sans clé API : OpenStreetMap jour (`https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png`) / CARTO Dark Matter nuit (`https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png`). Aucun filigrane tiers. Basculement via `setTileLayer()` sans résidu. |

---

## 10. Dossiers & Fichiers Clés (Repérage rapide)

```
src/
├── Entity/                 # 22 entités (Alert, User, Urgence72hCase, AlertTransmission, RuleConfig…)
├── Enum/                   # 20 enums (rôles, statuts, urgence, impact, fiabilité, priorité…)
├── Service/                # 16 services métier (Score, Import, Export, Escalation, Audit, Crypto…)
├── Controller/             # 14 contrôleurs (Alert, Dashboard, Wizard, Admin, Urgence, Auth, Audit)
├── Repository/             # 12 repositories (AlertRepository::findForUser critique)
├── Voter/                  # 3 voters (AlertVoter, UserVoter, SourceVoter)
├── EventSubscriber/        # 6 subscribers (AlertScoreSubscriber, AccessLog, ConnectionGeo…)
├── Message/ + MessageHandler/  # Bus async (escalades, SLA)
├── Form/                   # 7 formulaires (AlertType, QualificationType, TransmissionType…)
├── Dto/                    # 3 DTOs (AlertFilterDTO, AuditFilterDTO, CorridorFilterDTO)
├── Type/                   # EncryptedType (DBAL custom type)
├── Security/               # AuditVoter
├── Command/                # SlaWatcherCommand (cron 15min)
└── Kernel.php

config/
├── packages/
│   ├── security.yaml       # Firewalls, role_hierarchy, access_control
│   ├── workflow.yaml       # alert_lifecycle state_machine
│   ├── doctrine.yaml       # DBAL types (encrypted_string), ORM mapping
│   ├── messenger.yaml      # Transport doctrine://
│   ├── scheb_2fa.yaml      # Config 2FA TOTP
│   └── lexik_jwt_authentication.yaml
├── routes/                 # Annotations attributs PHP 8 (pas de YAML routes)

migrations/                 # 24 fichiers (Version20260817… → Version20260912…)

templates/                  # Twig (alert/, wizard/, dashboard/, admin/, emails/)

translations/               # Messages FR (validators, enums labels)
```

---

## 11. Écarts Techniques Non Résolus

| Problème | Impact | Contournement actuel |
|----------|--------|---------------------|
| `operateur_acteur` position Excel non confirmée | Export registre colonne AD potentiellement décalée | Colonne exportée en dernier (AD), documentée **[À CONFIRMER]** |
| Workflow états `ouvert`/`en_cours_analyse`/`ouvert_prioritaire` absents | Incohérence enum ↔ workflow | Utiliser états existants (`en_cours`, `a_investiguer`, `a_valider_saholty`) |
| API Platform sans ressources | Pas d'API REST standardisée | Controllers MVC classiques + endpoints JSON ponctuels (`/api/preview-score`) |
| Pas de tests E2E / contract tests | Régressions UI/API non détectées | Tests unitaires + intégration seulement |
| `messenger:consume` non supervisé (systemd/supervisor) | Messages escalades non traités si worker down | Lancer manuellement / via cron wrapper |
| Clé `APP_ENCRYPTION_KEY` unique pour tous les secrets | Rotation clé = perte TOTP tous users | Générer clé robuste au déploiement initial, ne jamais changer |

---

## 12. Références Code → Fonctionnel

| Fonctionnel | Technique (fichier:ligne) |
|-------------|---------------------------|
| Score GEI formule | `src/Service/ScoreCalculatorService.php:74-83` |
| Seuils configurables | `src/Service/ScoreCalculatorService.php:201-214` |
| Workflow transitions | `config/packages/workflow.yaml:24-53` |
| Rôles & hiérarchie | `src/Enum/UserRoleEnum.php:48-58`, `config/packages/security.yaml:37-43` |
| Urgence 72h création auto | `src/Service/EscalationRuleEngine.php:55-88` |
| Import détection colonnes | `src/Service/ImportService.php:311-370` |
| Export colonnes registre | `src/Service/ExportService.php:30-61` |
| Votant alerte (portée) | `src/Voter/AlertVoter.php:74-151` |
| Subscriber score auto | `src/EventSubscriber/AlertScoreSubscriber.php:62-119` |
| Commande SLA watch | `src/Command/SlaWatcherCommand.php:140-178` |
| Chiffrement TOTP | `src/Type/EncryptedType.php:50-72`, `src/Service/CryptoService.php:30-60` |