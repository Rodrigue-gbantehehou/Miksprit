# Paiements et intégrations provider

## Contrat d’ajout d’un provider

Un provider de paiement implémente `App\Service\Payment\PaymentProviderAdapter` :

- `code()` renvoie une clé stable en minuscules;
- `createCheckout()` ouvre une session de paiement hébergée;
- `verify()` relit la transaction directement chez le provider;
- `validateWebhook()` et `parseWebhook()` authentifient puis normalisent les événements;
- `webhookSignatureHeader()` indique le nom de l’en-tête de signature.

Enregistrez l’adaptateur dans Symfony avec le tag `miksprit.payment_provider`. Les cas d’usage appellent `PaymentProviderRegistry`; ils ne dépendent pas d’un SDK ou d’une API PSP précise. La référence, le montant XOF et la devise sont contrôlés avant qu’un paiement vérifié soit appliqué.

## FedaPay

FedaPay est le premier adaptateur et utilise la page de paiement hébergée : création d’une transaction, génération d’un token de paiement, puis redirection du client. Les retours navigateur ne valident jamais un paiement : la confirmation relit d’abord le statut via l’API FedaPay.

Valeurs par défaut dans `api/.env` :

```dotenv
FEDAPAY_ENABLED=0
FEDAPAY_SECRET_KEY=
FEDAPAY_WEBHOOK_SECRET=
FEDAPAY_BASE_URL=https://sandbox-api.fedapay.com/v1
PAYMENT_DEFAULT_PROVIDER=fedapay
PAYMENT_RETURN_URL=https://votre-domaine.example/portal
ROUTEROS_HOTSPOT_PROFILE=default
```

Mettez les secrets uniquement dans `api/.env.local` en développement ou dans le gestionnaire de secrets en production. Activez `FEDAPAY_ENABLED=1` après avoir renseigné la clé et le secret de webhook du même environnement (test ou production). La route webhook est `POST /api/v1/webhooks/fedapay`; configurez cette URL chez FedaPay et utilisez le secret webhook fourni pour cet endpoint. Le changement de fournisseur d’environnement doit se faire en même temps que les deux secrets et l’URL API.

La création passe deux appels réseau (transaction puis token). Une clé d’idempotence client est hachée et stockée avec la tentative; un retry identique reprend le lien déjà enregistré ou redemande un token pour la transaction existante. Si la réponse à la création de transaction est perdue avant que son identifiant soit reçu, la tentative reste bloquée à dessein : vérifiez les transactions FedaPay via leur métadonnée avant toute intervention manuelle pour éviter un double débit. La clé est aussi transmise en métadonnée pour corrélation; l’adaptateur ne prétend pas que FedaPay déduplique ses créations avec cette valeur.

## Achat public et activation

Le portail appelle `POST /api/v1/public/sites/{siteId}/checkout` avec `planId`, `email` et les noms facultatifs. L’API n’accepte que les forfaits publiés d’un site et d’une organisation actifs, fige leurs conditions dans une commande, puis renvoie la référence et l’URL hébergée. La limite publique est de 12 commandes par fenêtre de 5 minutes et par adresse IP/site.

Le retour navigateur contient `order_reference` et `siteId`; le portail interroge `GET /api/v1/public/orders/{order_reference}/status`. Une URL de retour n’est jamais une preuve de paiement. Seul le webhook FedaPay signé déclenche le statut payé; l’accès n’est renvoyé par le statut public qu’après provisioning confirmé par le routeur.

Après confirmation, une tâche `provision_paid_order` est ajoutée à l’outbox transactionnelle. Configurez un cron/planificateur pour exécuter `php bin/console miksprit:payments:process-paid-orders --limit=25` dans `api/` toutes les minutes. Le worker choisit un routeur actif du site, crée un compte hotspot avec les limites durée/données/appareils du forfait, puis chiffre le couple identifiant/mot de passe avant de le remettre au portail. Injectez `VOUCHER_CREDENTIAL_ENCRYPTION_KEY`, `ROUTEROS_HOTSPOT_PROFILE` et le secret `ROUTER_CREDENTIALS_JSON` avant d’accepter des paiements. Le propriétaire doit d’abord lancer « Vérifier et activer »; cette action ne réussit qu’après une réponse TLS effective du routeur.

Programmez également `php bin/console miksprit:vouchers:expire --limit=100` toutes les minutes pour retirer les comptes qui ont atteint leur date d’expiration. RouterOS reçoit aussi les limites de durée cumulée, de données et d’appareils du forfait.

Les messages au statut `processing` sont volontairement conservés si l’appel routeur a une issue ambiguë ou si le worker s’arrête après le claim. Ne les relancez pas automatiquement : comparez d’abord l’identifiant hotspot calculable à partir du voucher et l’utilisateur sur RouterOS pour éviter un doublon. Les messages `failed` nécessitent de corriger le routeur/configuration puis une procédure opérateur dédiée; aucun retry aveugle n’est prévu. La commande d’expiration affiche séparément les vouchers échus sans routeur associé; il faut les réconcilier manuellement. La migration rattache automatiquement les anciens vouchers seulement si leur site n’a qu’un routeur.

## Kits Wi-Fi

Un kit implémente `App\Service\Router\RouterDriver`, déclare un code stable via `code()` et est taggé `app.wifi_router_driver`. `RouterDriverRegistry` sélectionne le driver selon `Router.providerCode`; un code absent ou inconnu échoue sans bascule silencieuse vers MikroTik. Les secrets d’accès restent fournis par le résolveur de credentials et ne sont pas stockés dans le routeur.

Le driver installé actuellement est `mikrotik` (RouterOS). Une future intégration doit fournir son propre transport et ses propres opérations de provisioning/gestion avant qu’un routeur puisse être enrôlé avec ce code.
