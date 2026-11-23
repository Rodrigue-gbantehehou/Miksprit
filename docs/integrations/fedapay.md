# Intégration FedaPay

FedaPay est le premier adaptateur du contrat `TenantPaymentProviderAdapter`. Il utilise le checkout hébergé : création serveur de transaction avec la clé du propriétaire, génération du token et redirection. `FEDAPAY_ENABLED` reste à `0` par défaut afin qu'aucun paiement ne parte avant activation explicite de l’intégration.

## Vérification et confirmation

Le navigateur ne peut pas confirmer une vente. Le endpoint `POST /api/v1/webhooks/fedapay` retrouve la tentative avec l’identifiant PSP, puis valide `X-FEDAPAY-SIGNATURE`, l'horodatage et le corps brut avec le secret du propriétaire; l'événement est dédupliqué, puis l'application relit la transaction auprès du même compte FedaPay. Le fournisseur, l'identifiant enregistré, le montant XOF, la référence de commande et l'état approuvé doivent correspondre avant toute confirmation et provision.

Les réponses de création perdues avant réception d'un identifiant transactionnel ne sont pas retentées automatiquement, car le PSP peut déjà avoir créé une transaction. Il faut rechercher la métadonnée/reference marchande dans le tableau de bord ou l'API FedaPay, puis rapprocher la tentative avant reprise. Cela évite une double facturation.

## Configuration

Définir dans `api/.env.local` (ou le gestionnaire de secrets) :

- `FEDAPAY_ENABLED=1` seulement après préparation de la sandbox;
- `TENANT_PAYMENT_ENCRYPTION_KEY`, sous la forme `base64:` suivie d'une clé aléatoire de 32 octets encodée;
- `FEDAPAY_BASE_URL` demeure configurée côté serveur et limitée à l’API sandbox ou production;
- `PAYMENT_RETURN_URL` en HTTPS sur le domaine du portail.

Chaque propriétaire saisit sa clé API et le secret unique de son endpoint webhook dans l’espace propriétaire. La route webhook est commune à tous les comptes. Ne configurez jamais les clés d’un propriétaire dans le frontend ou dans les variables globales. La configuration peut être testée par une lecture seule de la liste de transactions avant enregistrement.

Chaque exploitant doit donc ouvrir et activer son propre compte marchand FedaPay, puis créer/récupérer ses identifiants API depuis ce compte avant de les enregistrer dans Miksprit. Miksprit fournit l'intégration logicielle; il ne crée pas les comptes marchands et ne reçoit pas les recettes des tickets. Les frais FedaPay applicables dépendent du contrat marchand de chaque exploitant.

Enregistrer `https://<domaine-api>/api/v1/webhooks/fedapay` comme endpoint webhook et injecter le secret exact associé. Ne jamais mettre de clé privée dans le frontend ni committer les secrets.

Les abonnements dus à Miksprit utilisent une configuration centrale séparée et un endpoint distinct, `POST /api/v1/webhooks/platform-subscriptions/fedapay`. Ce compte central et ses secrets sont gérés par l'exploitant de Miksprit; ils ne remplacent jamais la clé FedaPay du propriétaire pour ses ventes de tickets.

## Références officielles

- [Créer une transaction](https://docs.fedapay.com/api-reference/transactions/create.md)
- [Lire une transaction](https://docs.fedapay.com/api-reference/transactions/get-by-id.md)
- [Webhooks FedaPay](https://docs.fedapay.com/fr/integration-api/webhooks.md)
