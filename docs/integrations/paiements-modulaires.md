# Paiements et intégrations provider

## Paramètres marchands par tenant

La console propriétaire propose actuellement FedaPay comme fournisseur disponible. Les paramètres sont isolés par organisation et les identifiants saisis sont chiffrés avec `sodium_crypto_secretbox` dans `tenant_payment_settings`. Définissez `TENANT_PAYMENT_ENCRYPTION_KEY` dans le gestionnaire de secrets comme `base64:` suivi d'une clé aléatoire de 32 octets encodée en base64. Cette clé doit rester stable pour pouvoir relire les configurations; une rotation nécessite une procédure de rechiffrement.

Les routes `GET/PUT/DELETE /api/v1/owner/payment-settings` affichent et gèrent la configuration; `POST /api/v1/owner/payment-settings/test` vérifie la clé API en lecture seule via la recherche de transactions FedaPay. La réponse ne renvoie jamais les secrets. Le checkout public choisit le compte marchand enregistré pour le tenant du forfait. Les webhooks retrouvent le tenant depuis la référence de transaction enregistrée, valident la signature avec le secret de ce compte et relisent la transaction auprès du même compte avant le provisioning. Les identifiants ne peuvent être remplacés ou révoqués tant que des transactions sont en attente.

Le mode test ou production est choisi par tenant, mais chaque mode pointe exclusivement vers l’URL FedaPay officielle correspondante, jamais vers une URL entrée par l’utilisateur. `FEDAPAY_ENABLED=1` reste l’interrupteur global d’activation. Kkiapay n’est pas disponible tant qu’un adaptateur et ses contrôles ne sont pas implémentés.

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

Mettez `TENANT_PAYMENT_ENCRYPTION_KEY` uniquement dans `api/.env.local` en développement ou le gestionnaire de secrets en production, puis activez `FEDAPAY_ENABLED=1`. Chaque propriétaire fournit sa clé API et son secret webhook dans la console. La route webhook commune est `POST /api/v1/webhooks/fedapay`; chaque propriétaire configure cette URL dans son tableau de bord FedaPay et saisit le secret unique de cet endpoint. Les valeurs globales `FEDAPAY_SECRET_KEY` et `FEDAPAY_WEBHOOK_SECRET` ne servent qu’aux tentatives historiques sans configuration tenant.

La création passe deux appels réseau (transaction puis token). Une clé d’idempotence client est hachée et stockée avec la tentative; un retry identique reprend le lien déjà enregistré ou redemande un token pour la transaction existante. Si la réponse à la création de transaction est perdue avant que son identifiant soit reçu, la tentative reste bloquée à dessein : vérifiez les transactions FedaPay via leur métadonnée avant toute intervention manuelle pour éviter un double débit. La clé est aussi transmise en métadonnée pour corrélation; l’adaptateur ne prétend pas que FedaPay déduplique ses créations avec cette valeur.

## Achat public et activation

Le portail appelle `POST /api/v1/public/sites/{siteId}/checkout` avec `planId`, `email` et les noms facultatifs. L’API n’accepte que les forfaits publiés d’un site et d’une organisation actifs, fige leurs conditions dans une commande, puis renvoie la référence et l’URL hébergée. La limite publique est de 12 commandes par fenêtre de 5 minutes et par adresse IP/site.

Le retour navigateur contient `order_reference` et `siteId`; le portail interroge `GET /api/v1/public/orders/{order_reference}/status`. Une URL de retour n’est jamais une preuve de paiement. Seul le webhook FedaPay signé déclenche le statut payé; l’accès n’est renvoyé par le statut public qu’après provisioning confirmé par le routeur.

Après confirmation, une tâche `provision_paid_order` est ajoutée à l’outbox transactionnelle. Configurez un cron/planificateur pour exécuter `php bin/console miksprit:payments:process-paid-orders --limit=25` dans `api/` toutes les minutes. Le worker choisit un routeur actif du site, crée un compte hotspot avec les limites durée/données/appareils du forfait, puis chiffre le couple identifiant/mot de passe avant de le remettre au portail. Injectez `VOUCHER_CREDENTIAL_ENCRYPTION_KEY`, `ROUTER_CREDENTIAL_ENCRYPTION_KEY` et `ROUTEROS_HOTSPOT_PROFILE` avant l'enrôlement; l'exploitant saisit ses identifiants API dans la console propriétaire. Le propriétaire doit d’abord lancer « Vérifier et activer »; cette action ne réussit qu’après une réponse TLS effective du routeur.

Les nouvelles ventes ne calculent aucune commission Miksprit et ne créent aucune entrée de solde opérateur : le checkout utilise la clé du compte marchand du propriétaire. Les frais FedaPay éventuels restent facturés selon le contrat entre FedaPay et ce marchand. Les anciennes commandes et écritures ne sont pas effacées; les workers de rapprochement et reversements restent réservés aux données historiques.

Les reversements sont réservés par demande idempotente (`GET/POST /api/v1/owner/payouts`) et traités par `miksprit:payouts:process`. Ils restent réservés pendant les états `requested`, `processing`, `pending` et `ambiguous`; seuls les états terminaux vérifiés côté FedaPay sont marqués versé ou échec. Configurez `FEDAPAY_PAYOUTS_ENABLED=1` uniquement si cette capacité est activée sur le compte FedaPay. Une demande en état ambigu sans référence PSP exige un rapprochement manuel avant tout nouvel envoi. Les ventes en état `needs_review` ne créditent pas le portefeuille opérateur tant que leurs montants FedaPay ne sont pas rapprochés.

Programmez également `php bin/console miksprit:vouchers:expire --limit=100` toutes les minutes pour retirer les comptes qui ont atteint leur date d’expiration. RouterOS reçoit aussi les limites de durée cumulée, de données et d’appareils du forfait.

Les messages au statut `processing` sont volontairement conservés si l’appel routeur a une issue ambiguë ou si le worker s’arrête après le claim. Ne les relancez pas automatiquement : comparez d’abord l’identifiant hotspot calculable à partir du voucher et l’utilisateur sur RouterOS pour éviter un doublon. Les messages `failed` nécessitent de corriger le routeur/configuration puis une procédure opérateur dédiée; aucun retry aveugle n’est prévu. La commande d’expiration affiche séparément les vouchers échus sans routeur associé; il faut les réconcilier manuellement. La migration rattache automatiquement les anciens vouchers seulement si leur site n’a qu’un routeur.

## Kits Wi-Fi

Un kit implémente `App\Service\Router\RouterDriver`, déclare un code stable via `code()` et est taggé `app.wifi_router_driver`. `RouterDriverRegistry` sélectionne le driver selon `Router.providerCode`; un code absent ou inconnu échoue sans bascule silencieuse vers MikroTik. Les secrets d’accès restent fournis par le résolveur de credentials et ne sont pas stockés dans le routeur.

Le driver installé actuellement est `mikrotik` (RouterOS). Une future intégration doit fournir son propre transport et ses propres opérations de provisioning/gestion avant qu’un routeur puisse être enrôlé avec ce code.
