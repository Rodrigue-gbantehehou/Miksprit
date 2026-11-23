# Déploiement, sauvegardes et supervision

## Configuration avant déploiement

Pour les paiements SaaS dus à Miksprit, configurer séparément `PLATFORM_SUBSCRIPTION_PROVIDER=fedapay`, `PLATFORM_SUBSCRIPTIONS_REQUIRED=0|1`, `PLATFORM_SUBSCRIPTION_RETURN_URL` (HTTPS), `FEDAPAY_ENABLED=1`, `FEDAPAY_SECRET_KEY` et `FEDAPAY_WEBHOOK_SECRET` dans le gestionnaire de secrets. Créer une notification FedaPay distincte vers `/api/v1/webhooks/platform-subscriptions/fedapay`. Cette clé centrale ne sert qu'aux abonnements Miksprit; les ventes de tickets continuent d'utiliser les identifiants marchands propres aux tenants et leur endpoint webhook dédié. Laisser `PLATFORM_SUBSCRIPTIONS_REQUIRED=0` tant que l'offre et la recette commerciale ne sont pas validées; à `1`, tout nouvel espace sans abonnement actif ne peut pas exploiter le réseau.

Les tentatives d'accès aux routeurs échouent si `ROUTER_ALLOWED_HOSTS` ne contient pas l'hôte exact des routeurs autorisés (liste séparée par des virgules, sans wildcard). Cette liste applicative complète le filtrage egress du pare-feu; elle ne le remplace pas. N'autorisez pas les sous-réseaux privés génériques. Une connectivité entrante sûre depuis le cloud nécessite une adresse joignable ou un tunnel/VPN maîtrisé, ainsi qu'un certificat TLS valide pour le nom configuré.

Définir également `ROUTER_CREDENTIAL_ENCRYPTION_KEY` comme clé stable de 32 octets (ou `base64:` suivi de son encodage). Les exploitants peuvent alors saisir leurs identifiants API routeur dans la console; ils sont chiffrés par tenant. Une perte de cette clé empêche le déchiffrement des credentials enregistrés.

Copier `deploy/.env.example` vers `deploy/.env.local`, générer des mots de passe uniques et démarrer la base avec `docker compose --env-file deploy/.env.local -f deploy/compose.yaml up -d`. Les secrets API restent dans `api/.env.local` et ne doivent pas être commités. Définir aussi `TENANT_PAYMENT_ENCRYPTION_KEY` comme `base64:` suivi de l’encodage base64 d'une clé aléatoire de 32 octets (par exemple, générer avec `php -r 'echo "base64:".base64_encode(random_bytes(32)), PHP_EOL;'`). Conserver une sauvegarde protégée et la même valeur à travers les redémarrages, ou les identifiants marchands enregistrés deviendront illisibles. Le compose actuel ne déploie que MySQL; le serveur Symfony et les applications Next restent à déployer séparément derrière HTTPS et un reverse proxy.

Configurer les apps sous le même site de confiance afin que les cookies HttpOnly soient transmis. En HTTPS sur des sous-domaines contrôlés, régler `AUTH_COOKIE_DOMAIN` sur le domaine parent exact. Générer et protéger une clé RSA dédiée à l'environnement, configurer `JWT_PASSPHRASE` dans le gestionnaire de secrets et ne jamais déployer les clés de développement. Le JWT d'accès expire après 15 minutes; un cookie de renouvellement opaque, stocké uniquement sous forme de hash, est rotatif (14 jours d'inactivité, 30 jours maximum). Vérifier que `Version20261121170000` est appliquée sur la base cible avant d'activer le renouvellement. La base locale a été contrôlée à cette version le 21 novembre 2026; ne pas en déduire l'état d'une autre base. La déconnexion, la réinitialisation du mot de passe et la désactivation révoquent les sessions de renouvellement.

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

Lancer également `php bin/console miksprit:subscriptions:refresh --limit=500` et `php bin/console miksprit:routers:sync-sessions --limit=25` toutes les minutes afin d'actualiser les statuts d'abonnement et de rapprocher les sessions actives RouterOS en base. Configurez le planificateur en mode singleton pour empêcher deux synchronisations concurrentes. Un échec de polling routeur ne clôt pas une session; le passage suivant réessaiera. Les heures de début des sessions sont celles de leur première observation par Miksprit. Les paiements d'abonnement utilisent leur propre endpoint webhook et leur table d'historique; les commandes et paiements de tickets n'y sont jamais enregistrés.

MySQL a un healthcheck Compose. `GET /api/v1/health` vérifie l'API; les routes `/api/v1/admin/operations` affichent l'état de connexion DB et le nombre d'éléments outbox en attente. Le dépôt fournit `deploy/miksprit.cron.example` et `deploy/run-scheduled-task.sh` : adaptez les chemins et PHP, puis installez le cron sous le compte système de l'API (`crontab miksprit.cron`). Les paiements, l'expiration des tickets, l'actualisation des abonnements et la synchronisation des sessions routeur tournent chaque minute; le nettoyage des sessions de renouvellement tourne chaque jour à 03:15. Chaque tâche possède son verrou `flock` pour éviter les chevauchements. Le compte doit pouvoir écrire dans les répertoires de verrouillage et de logs. Vérifiez les logs dans `MIKSPRIT_LOG_DIR` après installation. Les tâches `miksprit:payments:settle-operator-sales` et `miksprit:payouts:process` ne concernent que les anciens flux centralisés et ne doivent pas être nécessaires aux ventes créées avec les comptes marchands des propriétaires. `FEDAPAY_ENABLED=1` active l'intégration après préparation du chiffrement et de l'URL HTTPS. Aucun taux de commission plateforme n'est nécessaire pour les nouvelles ventes. `messenger_worker` reste `not_configured`, car ces tâches n'utilisent pas encore Symfony Messenger. Supervisez disponibilité API/DB, outbox, éléments en échec/traitement, vouchers sans routeur, reversements historiques ambigus, abonnements à rapprocher, sauvegardes et capacité disque.

Ce dépôt ne configure pas encore de sauvegarde planifiée, réplication off-site, alertes, gestion de secrets, restauration automatique ni environnement de production. Faites des exercices de restauration réguliers et documentez RPO/RTO avant la mise en production. L'image Compose fournie est une base locale, pas une architecture HA.

## Administration globale

Les migrations `Version20261120120000`, `Version20261120123000`, `Version20261120130000`, `Version20261120140000`, `Version20261120150000` et `Version20261120160000` ajoutent les réglages de paiement tenant, l'unicité tenant des références, l'état de première configuration du mot de passe invité, les tables d'abonnement/paiements indépendants, les credentials routeur chiffrés par tenant et les données de rapprochement de session. `Version20261121170000` ajoute les sessions de renouvellement révocables. Les migrations ont été appliquées à la base locale jusqu'à cette version; l'état de production n'est pas connu. Avant tout déploiement, sauvegarder la base cible, lire `doctrine:migrations:status`, puis appliquer les versions absentes. La dernière migration historique de sessions retire l'unicité de la référence externe; son rollback peut échouer si un identifiant externe a été réutilisé. Ne pas exécuter de rollback après des écritures métier sans plan de conservation.

Les routes `/api/v1/admin/*` sont protégées par `ROLE_SUPER_ADMIN`. Les lectures attendues par le panneau couvrent tenants, routeurs, tentatives de paiement, ledger, audit et opérations. Les credentials routeur et codes voucher ne sont jamais inclus. Les lectures et actions passent par `admin_audit_events`; cette table est append-only au niveau de l'application.

L’opérateur crée son compte depuis la vitrine, puis se connecte et crée lui-même son espace depuis la console propriétaire (`POST /api/v1/owner/organizations`). Il y ajoute librement ses sites. L’administration globale ne crée ni organisations ni sites; elle peut consulter les données globales et gérer l’état des comptes. L'activation/désactivation utilise `PATCH /api/v1/admin/users/{id}/status` avec `{ "active": true|false }`; le dernier super-administrateur actif et son propre compte ne peuvent pas être désactivés depuis cet endpoint. Les écritures exigent le CSRF signé fourni par `/api/v1/auth/csrf` et renvoyé dans `_csrf_token`.

Vérifier `doctrine:migrations:status` dans chaque environnement avant d'utiliser les routes admin ou paramètres marchands; ne pas supposer que production partage l'état de la base locale. Le panneau `admin/` expose les métriques globales et l’état des comptes; `landing/` prend en charge l’inscription, la connexion et la réinitialisation de mot de passe.
