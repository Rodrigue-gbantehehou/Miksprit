# Miksprit — contrats initiaux

Ce document pose les frontières pour l'initialisation. Il ne prétend pas documenter un code déjà présent.

## Paiement confirmé avant accès

États de commande :

`pending_payment → payment_confirmed → provisioning → fulfilled`

États terminaux/exceptionnels : `payment_failed`, `payment_expired`, `provisioning_retry`, `provisioning_failed`, `refund_pending`, `refunded`.

Un paiement ne peut passer à `payment_confirmed` que par un handler serveur après validation de la signature webhook (si fournie) et interrogation de l'API PSP. La vérification compare le compte marchand, la référence interne, le montant, XOF et le statut terminal positif. Les retours navigateur ne changent aucun état de confiance.

Clés d'idempotence uniques : `(provider, provider_transaction_id)` et `(tenant_id, order_id, fulfillment_kind)`. Le handler webhook enregistre l'événement brut expurgé des secrets dans une inbox, puis répond rapidement. Une transaction SQL marque la commande payée et écrit l'événement outbox de provisioning. Un doublon ne crée pas de nouvel accès.

## Ports métier

### Paiement

- `PaymentProvider::createPayment(PaymentRequest): PaymentSession`
- `PaymentProvider::getPaymentStatus(ProviderReference): VerifiedPaymentStatus`
- `PaymentProvider::verifyWebhook(RawRequest): VerifiedPaymentEvent`
- `PaymentProvider::requestRefund(RefundRequest): RefundResult` (seulement si pris en charge et autorisé)

Le domaine ne dépend d'aucun SDK fournisseur. `VerifiedPaymentStatus` conserve la référence, le montant en unités mineures XOF, la devise, le marchand, l'état et l'horodatage observé.

### MikroTik

- `RouterAdapter::capabilities(): RouterCapabilities`
- `RouterAdapter::healthCheck(): RouterHealth`
- `RouterAdapter::provisionAccess(ProvisioningRequest): ProvisioningResult`
- `RouterAdapter::revokeAccess(AccessReference): ProvisioningResult`
- `RouterAdapter::listActiveSessions(SessionQuery): SessionPage`
- `RouterAdapter::disconnectSession(SessionReference): ProvisioningResult`

Une stratégie sélectionne l'API binaire ou REST selon la version/capacité du routeur. L'adaptateur reçoit un secret déchiffré uniquement au dernier moment depuis le coffre, jamais depuis une entité sérialisée ou une réponse API.

## Contrat multi-tenant

- Toute ressource propriétaire a un tenant propriétaire obligatoire.
- Le tenant actif vient de l'identité autorisée côté serveur, jamais d'un champ fourni librement par le client.
- Les services et repositories nécessitent un contexte tenant explicite; l'accès central est un chemin séparé et audité.
- Les jobs asynchrones transportent l'identifiant tenant et le revalident au traitement.
- Les clés d'unicité métier incluent le tenant quand l'identifiant est local.
- Les erreurs d'accès inter-tenant ne révèlent pas l'existence de la ressource.

## API REST indicative

- `GET /api/v1/public/sites/{publicId}/plans`
- `POST /api/v1/public/orders` — crée une commande et initialise le paiement; ne délivre pas l'accès.
- `GET /api/v1/public/orders/{publicId}` — retourne un état minimal via jeton de consultation aléatoire à durée limitée.
- `POST /api/v1/webhooks/fedapay` — endpoint public PSP; authentification par signature et inbox idempotente.
- `GET|POST /api/v1/owner/sites`, `/routers`, `/plans`, `/orders`, `/vouchers`, `/sessions`, `/reports` — accès tenant contrôlé.
- `/api/v1/admin/*` — autorisation centrale renforcée et audit obligatoire.

Les noms définitifs et schémas OpenAPI seront établis lors du squelette Symfony. Les secrets PSP, mots de passe routeur et codes voucher ne sont jamais renvoyés dans les endpoints d'administration génériques.

## Ledger initial

Écritures immuables et XOF : brut payé, frais PSP observés, commission théorique Miksprit, part théorique tenant, remboursement et état de règlement. Une écriture de part tenant est une créance comptable indicative et n'implique pas que Miksprit possède ou détient ces fonds. Aucun transfert réel n'est déclenché par le ledger.
