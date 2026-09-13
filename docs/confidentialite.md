# Documentation Confidentialité & Sécurité des Données — Plateforme GEI

> **Basée sur l'implémentation réelle** (code, entités, config, migrations) au 12/09/2026.  
> Concerne : RGPD, classification données, chiffrement, rétention, droits personnes, journalisation.

---

## 1. Classification des Données (selon entités)

| Niveau | Tables / Champs | Exemples | Protection |
|--------|-----------------|----------|------------|
| **TRÈS SENSIBLE** | `user.totpSecret` (chiffré), `user.password` (hash), `alert.emetteurTexte` (peut contenir noms réels) | Secrets 2FA, mots de passe, identités émetteurs brutes | Chiffrement au repos (libsodium) + hash argon2id + accès strict (Voters) |
| **SENSIBLE** | `alert.*` (contenu fiche : résumé, faits, hypothèses, pièces, commentaires), `alert_transmission.destinataire/note`, `notification.contenu` | Informations opérationnelles GEI, transmissions externes | Contrôle d'accès par rôle (portée marché), audit trail complet |
| **RESTREINT** | `market.*`, `liste_reference_valeur.*`, `rule_config.*` | Configuration, référentiels | Accès selon rôle (Superadmin/Manager) |
| **PUBLIC (interne)** | `user.nom/prenom/email/fonction`, `alert.codeGei/dateCreation/statut/niveauPriorite` | Identifiants fonctionnels, statuts | Visibilité selon portée (Agent = propres, Manager = marchés, SAHOLTY = toutes) |

> **Champ `sensibilite` (Enum `Sensibilite`)** : chaque alerte porte un niveau (`RESTREINTE`, `CONFIDENTIELLE`, `SECRETE`, `TRES_SECRETE`) — **métadonnée uniquement**, pas de contrôle technique automatique ajouté.

---

## 2. Chiffrement au Repos

### Implémentation (`EncryptedType` + `CryptoService`)
- **Algorithme** : libsodium `crypto_secretbox` (XSalsa20-Poly1305) — chiffrement authentifié (AEAD).
- **Nonce** : 24 octets aléatoires par chiffrement (préfixé au ciphertext).
- **Clé** : `APP_ENCRYPTION_KEY` (32 octets hex) → dérivée via `sodium_crypto_generichash` si longueur ≠ 32.
- **Stockage** : hex(nonce || ciphertext) dans colonne `VARCHAR(255)`.

### Données chiffrées
| Entité | Champ | Type Doctrine | Usage |
|--------|-------|---------------|-------|
| `User` | `totpSecret` | `encrypted_string` | Secret TOTP 2FA (base32) |

> **Rotation clé** : changement `APP_ENCRYPTION_KEY` = **perte définitive** de tous les secrets TOTP. Procédure : régénérer TOTP pour chaque utilisateur (désactiver/réactiver 2FA).

### Clés JWT (séparées)
- `JWT_SECRET_KEY` / `JWT_PUBLIC_KEY` : RSA 4096 (fichiers PEM) — **pas stockés en base**.
- `JWT_PASSPHRASE` : protège la clé privée sur disque.
- **Ne jamais réutiliser** `APP_ENCRYPTION_KEY` comme `JWT_PASSPHRASE`.

---

## 3. Contrôle d'Accès (Modèle RBAC + Portée Marché)

### Matrice d'accès aux alertes

| Rôle | Créer | Lire (propres) | Lire (marchés gérés) | Lire (toutes) | Modifier collecte | Qualifier | Décider (Valider/Rejeter) | Transmettre | Exporter | Surcharger score |
|------|-------|----------------|----------------------|---------------|-------------------|-----------|---------------------------|-------------|----------|------------------|
| Agent (`EMETTEUR_TERRAIN`) | ✓ | ✓ | ✗ | ✗ | ✓ (nouveau/a_completer) | ✗ | ✗ | ✗ | ✗ | ✗ |
| Manager (`PFT`) | ✓ | ✓ | ✓ | ✗ | ✓ (total permanent) | ✓ | ✓ | ✓ (si validée) | ✓ | ✓ |
| SAHOLTY | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Secrétariat GEI | ✗ | ✗ | ✗ | ✓ (vue) | ✗ | ✗ | ✗ | ✗ | ✓ | ✗ |
| Comité AIT | ✗ | ✗ | ✗ | ✓ (vue KPI) | ✗ | ✗ | ✗ | ✗ | ✓ | ✗ |
| Superadmin | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |

### Implémentation technique
- **Voters** : `AlertVoter` (8 attributs), `UserVoter` (gestion utilisateurs).
- **Repository** : `AlertRepository::findForUser($user, $filters)` — **point unique** d'application de la portée.
- **Workflow** : transitions conditionnées par `AlertVoter` (ex. `TRANSMIT` nécessite `DECIDE` + `statut = VALIDEE`).

---

## 4. Journalisation & Traçabilité (Audit Trail)

### `AccessLog` — Journal centralisé
Chaque entrée : `action` (enum 11 valeurs), `user`, `alert` (nullable), `ipAdresse`, `userAgent`, `resultat` (success/failed), `details`, `diffData` (JSON avant/après), `createdAt`.

**Actions tracées** :
| Code | Constante | Description |
|------|-----------|-------------|
| `creation` | `ACTION_CREATION` | Nouvelle alerte (Wizard/Import) |
| `modification` | `ACTION_MODIFICATION` | Édition alerte (Agent/Manager) |
| `export` | `ACTION_EXPORT` | Export Excel/PDF/Stats |
| `transmission` | `ACTION_TRANSMISSION` | Enregistrement transmission externe |
| `connexion` / `deconnexion` | `ACTION_CONNEXION` / `ACTION_DECONNEXION` | Auth |
| `qualification` | `ACTION_QUALIFICATION` | Soumission qualification Manager |
| `validation` | `ACTION_VALIDATION` | Validation Manager (statut → VALIDEE) |
| `rejet` | `ACTION_REJET` | Rejet Manager (statut → A_COMPLETER) |
| `surcharge` | `ACTION_SURCHARGE` | Surcharge manuelle score/priorité |
| `import` | `ACTION_IMPORT` | Import lot Excel |

### Historique métier dédié
| Table | Contenu | Immuable |
|-------|---------|----------|
| `alert_status_history` | Transitions workflow : ancien/nouveau statut, auteur, transition, justification | ✓ |
| `alert_qualification_history` | Snapshots scoring : tous critères + score + seuils appliqués + `calculePar` | ✓ |
| `alert_comment` | Commentaires typés (agent/manager/system) + auteur + date | ✓ |
| `alert_transmission` | Transmissions : destinataire, validations PFT/SAHOLTY + horodatages | ✓ |

### RGPD — Droit d'accès / portabilité
- Export complet possible via `ExportController` (Excel/PDF) — filtres + portée respectées.
- Aucune API "télécharger mes données" dédiée — à implémenter si requis légal.

---

## 5. Conservation & Suppression

### Règles actuelles (implémentées)
| Donnée | Conservation | Suppression |
|--------|--------------|-------------|
| `Alert` | **Indéfinie** (soft delete `deletedAt`) | Soft delete uniquement — pas de purge auto |
| `AccessLog` | Indéfinie | Aucune |
| `AlertQualificationHistory` | Indéfinie (immuable) | Aucune |
| `AlertStatusHistory` | Indéfinie | Aucune |
| `Notification` | Indéfinie | Aucune |
| `User` | Indéfinie (`actif` booléen) | Désactivation (`actif=false`) — pas de suppression RGPD |
| `Urgence72hCase/Phase` | Indéfinie | Statut `cloturee` — pas de suppression |

> **[ÉCART / À VENIR]** : Aucune politique de rétention paramétrable, aucune anonymisation automatique après délai, aucune procédure "droit à l'oubli" (suppression effective + logs). À définir selon règlementation applicable.

---

## 6. Transferts & Destinataires Externes

### Transmissions enregistrées (`AlertTransmission`)
- **Destinataires** : Douanes, Police, Autorités fiscales, Partenaires, Interpol, etc. (champ libre `destinataire`).
- **Double validation** requise si score 14–17 : PFT **ET** SAHOLTY (`validePftBy`, `valideSaholtyBy` + dates).
- **Traçabilité** : `transmisBy` (Manager), `transmisLe` (immuable), `noteTransmission`, `statut` (`en_cours`/`cloture`).

### Emails (Notifier + Messenger)
- **Expéditeur** : `gei@example.com` (configurable via `MAILER_DSN`).
- **Destinataires** : utilisateurs internes (Managers, SAHOLTY) selon règles escalade.
- **Contenu** : code GEI, score, marché, priorité, résumé tronqué (80 chars), lien vers fiche.
- **Pas d'envoi vers destinataires externes** via email — seulement notification interne + transmission enregistrée.

---

## 7. Sécurité Technique Renforcée

### Authentification
- **Formulaire** : CSRF + remember_me (7j, httponly, samesite=lax) + throttling (5/15min).
- **JWT** : RS256, clés RSA 4096, expiration configurable (LexikJWTAuthenticationBundle).
- **2FA TOTP** : RFC 6238, SHA1, période 30s, 6 digits — activation par utilisateur, secret chiffré en base.

### Mots de passe
- **Hash** : `auto` (Symfony 7.3 → argon2id par défaut, fallback bcrypt).
- **Test** : coût réduit (cost=4, time_cost=3, memory_cost=10).

### En-têtes HTTP (`SecurityHeadersSubscriber`)
```
Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; frame-ancestors 'none'; form-action 'self'; base-uri 'self';
X-Frame-Options: DENY
X-Content-Type-Options: nosniff
Referrer-Policy: strict-origin-when-cross-origin
Permissions-Policy: geolocation=(), microphone=(), camera=()
```

### CORS (`nelmio_cors.yaml`)
- Défaut `.env.dist` : `^https?://(localhost|127\.0\.0\.1)(:[0-9]+)?$` — **à restreindre en prod**.

### Injection SQL / XSS
- **Doctrine ORM** : DQL/QueryBuilder + paramètres liés — pas de concaténation brute.
- **Twig** : auto-escapage activé par défaut (`autoescape: true`).
- **Formulaires** : CSRF tokens sur tous les formulaires state-changing.

---

## 8. Gestion des Incidents de Sécurité

### Détection
- `AccessLog` : toutes actions `resultat = 'failed'` (connexions, exports, modifications).
- `connection_position` : géolocalisation connexions (subscriber `ConnectionGeoSubscriber`) — détection pays inhabituels.
- Logs Symfony (`var/log/prod.log`) + Messenger failed messages (`messenger_messages` table).

### Réponse (procédure manuelle actuelle)
1. Identifier comptes compromis via `AccessLog` (IP, user-agent, horodatage).
2. Désactiver utilisateur (`User.actif = false`).
3. Révoquer sessions (vider `messenger_messages` + cache sessions).
4. Régénérer `APP_SECRET` + clés JWT + `APP_ENCRYPTION_KEY` → **impact : reset TOTP tous users**.
5. Notifier autorités si données sensibles exposées (selon règlementation).

> **[ÉCART / À VENIR]** : Pas de verrouillage auto compte après N échecs (seul throttling login), pas de détection anomalie comportementale (impossible voyage, heuristiques), pas de SIEM / alerting automatisé.

---

## 9. Conformité RGPD — État des Lieux

| Exigence RGPD | Statut | Détail |
|---------------|--------|--------|
| **Registre des traitements** | **Partiel** | Ce doc + code = base, mais pas de registre formel DPO |
| **Minimisation données** | ✅ | Champs strictement métier (Annexe A/B/C), pas de tracking tiers |
| **Portée d'accès (need-to-know)** | ✅ | RBAC + portée marché strictement appliquée (Voters + Repository) |
| **Chiffrement données sensibles** | ✅ | TOTP secret (libsodium AEAD), MDP hash argon2id |
| **Journalisation accès** | ✅ | `AccessLog` complet + historique métier dédié |
| **Droit d'accès / portabilité** | **Partiel** | Export Excel/PDF via UI (filtres + portée), pas d'API dédiée |
| **Droit à l'effacement** | ❌ | Soft delete seulement, pas de suppression effective + purge logs |
| **Limitation conservation** | ❌ | Conservation indéfinie par défaut, pas de politique paramétrable |
| **Sécurité par conception** | ✅ | Chiffrement, RBAC, headers, CSRF, 2FA, prepared statements |
| **Analyse d'impact (DPIA)** | ❌ | Non réalisée formellement |
| **Sous-traitants (processeurs)** | N/A | Hébergement DB (Docker/PostgreSQL) — contrat à vérifier |
| **Violations données (notification 72h)** | **Procédure manuelle** | Détection via logs, pas d'automatisation |

---

## 10. Checklist Déploiement Sécurisé (Production)

### Obligatoire avant mise en prod
- [ ] `APP_SECRET` : valeur aléatoire 32+ chars (générée, pas `changeme`)
- [ ] `APP_ENCRYPTION_KEY` : `php -r "echo bin2hex(random_bytes(32));"` — **sauvegarder hors repo**
- [ ] Clés JWT : `openssl genrsa -aes256 ...` + `openssl rsa -pubout ...` — passphrase forte dans `.env.local`
- [ ] `DATABASE_URL` : credentials dédiés prod (pas `app/!ChangeMe!`), TLS activé si DB managée
- [ ] `MAILER_DSN` : provider réel (SendGrid, Mailgun, SMTP TLS) — pas `null://null`
- [ ] `CORS_ALLOW_ORIGIN` : domaine(s) prod uniquement (ex. `^https://gei\.example\.org$`)
- [ ] `composer dump-env prod` : compilation `.env` optimisée
- [ ] `php bin/console cache:clear --env=prod`
- [ ] `php bin/console doctrine:migrations:migrate --env=prod --no-interaction`
- [ ] Worker Messenger supervisé (systemd/supervisor) : `messenger:consume async --memory-limit=128M --time-limit=3600`
- [ ] Cron `app:sla:watch` actif (toutes les 15 min)
- [ ] HTTPS forcé (termination TLS reverse proxy / load balancer)
- [ ] Sauvegardes DB quotidiennes + test restauration mensuel
- [ ] Monitoring : logs centralisés (Loki/ELK), alertes erreurs 5xx + `AccessLog` failed

### Recommandé
- [ ] WAF (ModSecurity / Cloudflare) devant l'application
- [ ] Rate limiting global (Symfony RateLimiter ou reverse proxy)
- [ ] Scan vulnérabilités dépendances (`composer audit` / `npm audit` si assets)
- [ ] Tests pénétration annuels
- [ ] Formation équipe phishing / ingénierie sociale

---

## 11. Références Code — Points Sécurité

| Sujet | Fichier:Ligne |
|-------|---------------|
| User + rôles + TOTP | `src/Entity/User.php:52-54, 90-95, 363-399` |
| Voters (autorisations) | `src/Voter/AlertVoter.php`, `src/Voter/UserVoter.php` |
| Repository portée | `src/Repository/AlertRepository.php:22-150` |
| Chiffrement TOTP | `src/Type/EncryptedType.php:50-72`, `src/Service/CryptoService.php:30-60` |
| Security headers | `src/EventSubscriber/SecurityHeadersSubscriber.php` |
| 2FA enforcement | `src/EventSubscriber/TwoFactorEnforcementSubscriber.php` |
| AccessLog (audit) | `src/Entity/AccessLog.php`, `src/EventSubscriber/AccessLogSubscriber.php` |
| Workflow (transitions contrôlées) | `config/packages/workflow.yaml` |
| Security.yaml (firewalls, hierarchy, access_control) | `config/packages/security.yaml` |
| .env.dist (variables secrets) | `.env.dist:23, 36, 58-60, 67` |