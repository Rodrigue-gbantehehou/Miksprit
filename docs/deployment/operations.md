# Déploiement, sauvegardes et supervision

## Configuration locale

Copier `deploy/.env.example` vers `deploy/.env.local`, générer des mots de passe uniques et démarrer la base avec `docker compose --env-file deploy/.env.local -f deploy/compose.yaml up -d`. Les secrets API restent dans `api/.env.local` et ne doivent pas être commités. Le compose actuel ne déploie que MySQL; le serveur Symfony et les applications Next restent à déployer séparément derrière HTTPS et un reverse proxy.

Configurer les apps sous le même site de confiance afin que les cookies JWT HttpOnly soient transmis. En HTTPS sur des sous-domaines contrôlés, régler `AUTH_COOKIE_DOMAIN` sur le domaine parent exact. Générer et protéger une clé RSA dédiée à l'environnement, configurer `JWT_PASSPHRASE` dans le gestionnaire de secrets et ne jamais déployer les clés de développement. Le renouvellement de JWT reste à mettre en place avant d'exiger des connexions de longue durée.

## Sauvegarde/restauration locale

`deploy/backup.ps1` crée un dump SQL daté hors du dépôt par défaut (`$env:USERPROFILE/miksprit-backups`). Exemple :

```powershell
.\deploy\backup.ps1
.\deploy\backup.ps1 -DestinationDirectory 'D:\Backups\Miksprit'
```

Le dump contient des données sensibles. Chiffrez-le au repos, limitez les accès, conservez une copie hors site et définissez une rétention. Restaurez d'abord dans une base isolée :

```powershell
.\deploy\restore.ps1 -BackupFile "$env:USERPROFILE\miksprit-backups\miksprit-20261114-120000.sql"
```

Le script exige la saisie de `RESTORE`, mais cela n'est pas une protection suffisante pour une base de production. Vérifiez que l'instance, le nom de base et la sauvegarde sont corrects avant de confirmer. Après restauration, contrôlez `doctrine:migrations:status`, les données et le parcours applicatif.

## Supervision

MySQL a un healthcheck Compose. `GET /api/v1/health` vérifie l'API; les routes `/api/v1/admin/operations` affichent l'état de connexion DB et le nombre d'éléments outbox en attente. Le traitement applicatif existe en commandes console, mais doit être lancé par un planificateur supervisé : `php bin/console miksprit:payments:settle-operator-sales --limit=100`, `php bin/console miksprit:payouts:process --limit=25`, `php bin/console miksprit:payments:process-paid-orders --limit=25` et `php bin/console miksprit:vouchers:expire --limit=100` toutes les minutes. `FEDAPAY_PAYOUTS_ENABLED=1` ne doit être utilisé qu'après activation des reversements auprès de FedaPay; le taux `PLATFORM_COMMISSION_BPS` doit être fixé avant d'activer les paiements clients. `messenger_worker` reste `not_configured`, car ces tâches n'utilisent pas encore Symfony Messenger. Exposez les états à un moniteur externe qui alerte sur disponibilité API/DB, profondeur et ancienneté de l'outbox, éléments en échec/traitement, ventes en `needs_review`, vouchers sans routeur, reversements ambigus, espace disque, échecs de sauvegarde et âge de la dernière sauvegarde.

Ce dépôt ne configure pas encore de sauvegarde planifiée, réplication off-site, alertes, gestion de secrets, restauration automatique ni environnement de production. Faites des exercices de restauration réguliers et documentez RPO/RTO avant la mise en production. L'image Compose fournie est une base locale, pas une architecture HA.

## Administration globale

Les routes `/api/v1/admin/*` sont protégées par `ROLE_SUPER_ADMIN`. Les lectures attendues par le panneau couvrent tenants, routeurs, tentatives de paiement, ledger, audit et opérations. Les credentials routeur et codes voucher ne sont jamais inclus. Les lectures et actions passent par `admin_audit_events`; cette table est append-only au niveau de l'application.

Les invitations owner se font avec `POST /api/v1/admin/users` (`email`, `organizationName`, `slug`). Le mot de passe aléatoire initial n'est jamais communiqué : l'API envoie un lien à usage unique via la configuration mail du backend. L'activation/désactivation utilise `PATCH /api/v1/admin/users/{id}/status` avec `{ "active": true|false }`; le dernier super-administrateur actif et son propre compte ne peuvent pas être désactivés depuis cet endpoint. Les écritures exigent le CSRF signé fourni par `/api/v1/auth/csrf` et renvoyé dans `_csrf_token`.

Vérifier `doctrine:migrations:status` dans l'environnement cible avant d'utiliser les routes admin. La base locale est actuellement à `Version20261114145336`. Le panneau `admin/` expose les métriques globales et la gestion des comptes; `landing/` prend en charge les liens d'invitation/réinitialisation.
