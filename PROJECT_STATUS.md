# Miksprit — état du projet

Dernière mise à jour : 2026-11-14

## État du jalon paiement et accès Wi-Fi

Le parcours applicatif est câblé de bout en bout : catalogue public, création idempotente d'une commande et d'une tentative, checkout hébergé FedaPay configurable, retour navigateur sans confiance, webhook signé suivi d'une vérification serveur FedaPay, confirmation atomique, outbox, provisioning RouterOS, remise conditionnelle des identifiants et expiration du voucher. Les interfaces paiement et routeur sont extensibles par adaptateurs enregistrés; MikroTik est le premier driver.

La console propriétaire permet de sélectionner le driver Wi-Fi lors de l'enrôlement et de vérifier un routeur avant son activation. L'API accepte uniquement les drivers enregistrés. Le statut public du paiement n'expose les identifiants qu'après confirmation du provisioning.

## Configuration avant essai réel

- Dans `api/.env.local` ou le gestionnaire de secrets, renseigner les clés FedaPay et le secret du webhook du même environnement, `FEDAPAY_ENABLED=1`, ainsi qu'une vraie URL HTTPS dans `PAYMENT_RETURN_URL`.
- Générer `VOUCHER_CREDENTIAL_ENCRYPTION_KEY` au format documenté; configurer `ROUTEROS_HOTSPOT_PROFILE` et fournir `ROUTER_CREDENTIALS_JSON` par un gestionnaire de secrets.
- Configurer la tâche planifiée `php bin/console miksprit:payments:process-paid-orders --limit=25` et `php bin/console miksprit:vouchers:expire --limit=100` chaque minute. Prévoir supervision et rapprochement manuel des messages ambigus.
- Examiner les migrations en attente puis appliquer `doctrine:migrations:migrate` explicitement dans l'environnement choisi. Aucune migration n'a été exécutée pendant ce travail.
- Vérifier le webhook FedaPay, le réseau TLS sortant, le profil hotspot et le parcours complet dans l'environnement sandbox avant toute activation en production.

## Architecture et garde-fous

- Un fichier par entité sous `api/src/Entity`, services regroupés par domaine sous `api/src/Service`, événements sous `api/src/Event` et repositories plats sous `api/src/Repository`.
- `PaymentProviderRegistry` et `RouterDriverRegistry` sélectionnent des adaptateurs taggés; une valeur absente ou inconnue échoue sans bascule silencieuse.
- La notification signée n'est pas considérée comme preuve suffisante : l'état est relu côté serveur auprès du PSP et comparé à la commande, au montant et à la devise.
- Le ledger est append-only. Le provisioning est idempotent et fermé en cas de réponse réseau ambiguë; aucune répétition automatique ne risque de créer un second compte. Ces cas exigent une réconciliation opérateur.
- Les secrets ne sont pas renvoyés par l'API; les identifiants voucher sont chiffrés et ne sont exposés qu'au détenteur de la référence aléatoire de commande après provisioning.

## Vérifications réalisées

- Validation de configuration Symfony (`lint:container`) et mapping Doctrine (`doctrine:schema:validate --skip-sync`).
- Routes checkout, état public, webhook et vérification/choix du driver propriétaire enregistrées; commandes de provisioning et d'expiration découvertes.
- `composer validate` est valide, avec un avertissement préexistant sur la contrainte exacte Lexik JWT.
- ESLint est passé. La vérification TypeScript a détecté un champ manquant dans le type partagé `Router`; correction appliquée, contrôle à relancer.
- Aucun test automatisé, appel FedaPay/RouterOS, migration ou synchronisation de base n'a été exécuté.

## Reste pour l'exploitation complète du produit

1. Fournir les paramètres locaux/secrets, créer le super-admin, vérifier le mail et les flux JWT depuis un navigateur.
2. Appliquer et contrôler les migrations dans une base choisie.
3. Exécuter la recette sandbox réelle du parcours paiement → voucher → accès, puis valider l'expiration sur un routeur de test.
4. Déployer worker/cron durables, alertes, CI, sauvegardes chiffrées planifiées et exercice de restauration.
5. Compléter la gestion des membres propriétaire, le renouvellement JWT et les drivers des futurs kits Wi-Fi/PSP selon leurs contrats.
