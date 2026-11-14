# Services applicatifs et adaptateurs

## Organisation du code

Le projet conserve les dossiers Symfony usuels : entités dans `api/src/Entity/` (un fichier par entité), règles et cas d'usage réutilisables dans `api/src/Service/<Domaine>/`, événements dans `api/src/Event/`, repositories sous `api/src/Repository/` et contrôleurs HTTP séparés. Les contrats des intégrations résident dans les domaines qui les consomment; les adaptateurs concrets sont sous `Service/Provider/`.

`ResolveTenantContext` construit le tenant actif à partir du principal authentifié et vérifie l'appartenance active. Un identifiant tenant fourni par le navigateur n'est jamais une preuve d'autorisation.

## Paiement modulaire

`PaymentProviderAdapter` définit les opérations communes de création/reprise du checkout, vérification et traitement de webhook. `PaymentProviderRegistry` choisit le fournisseur par code. FedaPay est l'adaptateur initial; un nouveau PSP doit implémenter le contrat, enregistrer son tag et sa configuration sans modifier les cas d'usage.

`CreatePublicWifiCheckout` valide le plan publié et le site/tenant actifs, fige le prix, crée une référence aléatoire et protège les réessais avec une clé d'idempotence hachée. Une tentative existante est reprise sans créer une nouvelle transaction. Si le résultat de création PSP est inconnu avant réception de son identifiant, l'API échoue fermé et demande une réconciliation opérateur afin d'éviter un double débit.

Une URL de checkout ou de retour navigateur ne confirme jamais un paiement. `FedaPayGateway` valide la signature du webhook, puis relit la transaction côté serveur et compare le fournisseur, la référence, le montant, XOF et l'état approuvé avant la confirmation. L'inbox déduplique les événements; la confirmation, les écritures de ledger et la création de l'outbox sont transactionnelles.

## Provisioning et adaptateurs Wi-Fi

Le worker `ProcessPaidOrderOutbox` ne traite que les commandes payées. Il réserve un voucher, sélectionne un routeur actif du site puis passe par `RouterDriverRegistry`. `RouterDriver` est le contrat extensible pour les futurs kits. MikroTik est enregistré comme driver `mikrotik` et applique les limites durée, données et appareils du forfait.

Les identifiants sont générés aléatoirement, chiffrés avant persistance, puis exposés par l'endpoint public uniquement une fois le provisioning confirmé. Le worker d'expiration retire les comptes RouterOS échus. Les résultats réseau ambigus gardent une claim pour rapprochement manuel; ils ne sont pas rejoués automatiquement.

## Exploitation

- `php bin/console miksprit:payments:process-paid-orders --limit=25`
- `php bin/console miksprit:vouchers:expire --limit=100`

Planifier les commandes et surveiller l'âge/profondeur de l'outbox, les erreurs, les vouchers sans routeur associé et les claims en rapprochement. Les secrets API, webhook, routeur et chiffrement sont injectés par environnement; ne pas les versionner. Les clés FedaPay et le webhook doivent correspondre au même mode sandbox ou production.
