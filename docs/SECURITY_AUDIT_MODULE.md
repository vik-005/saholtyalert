# Module de Sécurité GEI - Documentation d'Installation

## Vue d'ensemble

Le module de sécurité GEI fournit une solution complète de :
- Journalisation centralisée des événements de sécurité
- Détection des intrusions (brute force, scanning, automatisation)
- Scoring de risque en temps réel
- Gestion des adresses IP (blocage/liste blanche)
- Interface d'administration professionnelle

## Installation

### 1. Base de données

Exécuter la migration :
```bash
php bin/console doctrine:migrations:migrate
```

### 2. Services

Les services suivants sont automatiquement enregistrés :
- `App\Service\RiskScoringService` - Calcul du score de risque
- `App\Service\IntrusionDetectionService` - Détection des intrusions
- `App\Service\IpReputationService` - Réputation des IP
- `App\Service\IpBlockingService` - Gestion du blocage
- `App\Service\ThreatIntelligenceService` - Threat Intelligence

### 3. Event Subscribers

Les subscribers sont auto-enregistrés via les annotations :
- `SecurityAuditSubscriber` - Capture tous les événements HTTP
- `AuthenticationFailureListener` - Capture les échecs d'authentification

### 4. Configuration optionnelle

Modifier `config/packages/security_audit.yaml` pour activer :
- IPInfo (géolocalisation)
- AbuseIPDB (réputation IP)
- MaxMind (détection VPN/Proxy)

## Utilisation

### Accès au Security Center

URL : `/admin/security`
Rôle requis : `ROLE_SUPERADMIN`

### Tableau de bord

Affiche :
- Nombre total de connexions
- Connexions réussies/échouées
- IP uniques détectées
- IP bloquées
- Tentatives suspectes

### Journal d'audit

Accessible via `/admin/security/audit`
Filtres disponibles :
- Type d'événement
- Adresse IP
- Date de début/fin
- Score de risque minimum

### Analyse des IP

Accessible via `/admin/security/ip`
Actions :
- Visualiser les IP suspectes
- Bloquer/débloquer des IP
- Ajouter à la liste blanche

### Alertes de sécurité

Accessible via `/admin/security/alerts`
Statuts : Nouveau → Reconnu → En cours → Résolu

### Surveillance des routes

Accessible via `/admin/security/routes`
Affiche les routes les plus consultées et celles avec des erreurs

## Structure des entités

### SecurityAuditLog
Enregistre tous les événements de sécurité avec :
- Adresse IP complète
- Route Symfony
- Score de risque (0-100)
- Niveau de risque (normal/suspicious/probable/threat)
- Facteurs de risque détaillés

### IpBlocklist
Gère le blocage des IP avec :
- Liste noire et liste blanche
- Durée de blocage configurable
- Raison du blocage
- Historique des actions

### SecurityAlert
Alertes automatiques pour :
- Brute force
- Scanning de routes
- Requêtes automatisées
- Accès non autorisé
- Comportements suspects

## Configuration du scoring de risque

```
0-30   : Activité normale
31-60  : Activité suspecte
61-80  : Attaque probable
81-100 : Menace élevée
```

Facteurs contributifs :
- Type d'événement
- Tentatives d'authentification échouées
- User-Agent suspect
- Détection VPN/Proxy/Tor
- Taux de requêtes

## Maintenance

### Nettoyage des blocages expirés
```bash
php bin/console app:security:cleanup-expired
```

### Rafraîchissement des reputations IP
```bash
php bin/console app:security:refresh-reputations
```

## Considérations de sécurité

- Les journaux d'audit sont immutables après création
- Les mots de passe et jetons ne sont jamais journalisés
- La géolocalisation IP est indicative (jamais précise à 100%)
- Les décisions de blocage incluent une protection contre les faux positifs