# Miksprit — état du projet

Mis à jour le 22 novembre 2026. Ce document décrit le dépôt et l'environnement local contrôlé; il ne certifie pas un déploiement ni une recette réelle.

## Fonctionnalités présentes

- Espace opérateur : organisations, sites, forfaits, routeurs MikroTik, sessions, transactions, configuration FedaPay propre au fournisseur, membres et abonnements Miksprit.
- Paiements de tickets directement sur le compte marchand de l'opérateur; aucun reversement Miksprit sur les nouvelles ventes. FedaPay est le seul fournisseur de paiement intégré.
- JWT d'accès de 15 minutes et jeton de renouvellement opaque rotatif (14 jours d'inactivité, 30 jours maximum), révocable à la déconnexion, à la réinitialisation du mot de passe et à la désactivation du compte.
- Migrations de paiement tenant, abonnement, routeurs et renouvellement de session présentes. La base locale `db_miksprit` a été contrôlée à `Version20261121170000`; l'état de toute autre base, notamment production, doit être vérifié indépendamment.
- Workflow CI dans `.github/workflows/ci.yml` pour l'API et les trois applications. Son exécution distante sur GitHub reste à confirmer.

## État de préparation production

**Pas encore prêt à déployer.** Le compose fourni démarre MySQL uniquement. L'API et les interfaces nécessitent un hébergement, HTTPS, un reverse proxy et leurs URL de production. Les exemples d'environnement contiennent des valeurs locales ou factices; configurer les clés, le fournisseur e-mail, la base et les URL de retour dans un gestionnaire de secrets.

À préparer avant lancement :

1. Héberger Symfony, client, admin et landing sous un domaine compatible avec les cookies; renseigner toutes les variables d'environnement avant les builds Next.js.
2. Sauvegarder la base cible, vérifier `doctrine:migrations:status`, puis appliquer seulement les migrations absentes.
3. Planifier et superviser chaque minute les tâches `miksprit:payments:process-paid-orders`, `miksprit:vouchers:expire`, `miksprit:subscriptions:refresh` et `miksprit:routers:sync-sessions`, puis purger les sessions de renouvellement expirées chaque jour avec `miksprit:auth:prune-refresh-sessions`.
4. Configurer FedaPay marchand et plateforme séparément, les webhooks HTTPS, le mailer et les secrets de chiffrement/JWT.
5. Définir les hôtes routeur autorisés, les règles egress et TLS; recetter sur un vrai MikroTik.
6. Mettre en place sauvegardes chiffrées hors site, alertes et exercice de restauration.
7. Décider le prix et les règles de l'abonnement avant d'activer `PLATFORM_SUBSCRIPTIONS_REQUIRED=1`.

Les essais FedaPay réels, la livraison de courriels et un équipement MikroTik physique ne sont pas attestés ici. Les changements présents dans le dépôt doivent encore passer par la revue et le workflow CI avant livraison.

## Références

- [État d'implémentation du cahier des charges](docs/architecture/cahier-des-charges-implementation.md)
- [Déploiement, sauvegardes et supervision](docs/deployment/operations.md)
- [Configuration locale MySQL](deploy/README.md)
