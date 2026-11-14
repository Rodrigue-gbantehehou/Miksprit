# Intégration FedaPay

FedaPay est le premier adaptateur du contrat `PaymentProviderAdapter`. Il utilise le checkout hébergé : création serveur de transaction, génération du token et redirection. `FEDAPAY_ENABLED` reste à `0` par défaut afin qu'aucun paiement ne parte avant configuration explicite.

## Vérification et confirmation

Le navigateur ne peut pas confirmer une vente. Le endpoint `POST /api/v1/webhooks/fedapay` valide `X-FEDAPAY-SIGNATURE`, l'horodatage et le corps brut; l'événement est dédupliqué, puis l'application relit la transaction dans l'API FedaPay. Le fournisseur, l'identifiant enregistré, le montant XOF, la référence de commande et l'état approuvé doivent correspondre avant toute confirmation et provision.

Les réponses de création perdues avant réception d'un identifiant transactionnel ne sont pas retentées automatiquement, car le PSP peut déjà avoir créé une transaction. Il faut rechercher la métadonnée/reference marchande dans le tableau de bord ou l'API FedaPay, puis rapprocher la tentative avant reprise. Cela évite une double facturation.

## Configuration

Définir dans `api/.env.local` (ou le gestionnaire de secrets) :

- `FEDAPAY_ENABLED=1` seulement après préparation de la sandbox;
- `FEDAPAY_API_KEY` et `FEDAPAY_WEBHOOK_SECRET` issus du même environnement;
- `FEDAPAY_BASE_URL` sandbox ou production;
- `PAYMENT_RETURN_URL` en HTTPS sur le domaine du portail.

Enregistrer `https://<domaine-api>/api/v1/webhooks/fedapay` comme endpoint webhook et injecter le secret exact associé. Ne jamais mettre de clé privée dans le frontend ni committer les secrets.

## Références officielles

- [Créer une transaction](https://docs.fedapay.com/api-reference/transactions/create.md)
- [Lire une transaction](https://docs.fedapay.com/api-reference/transactions/get-by-id.md)
- [Webhooks FedaPay](https://docs.fedapay.com/fr/integration-api/webhooks.md)
