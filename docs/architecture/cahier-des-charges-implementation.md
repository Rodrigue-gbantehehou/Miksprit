# Cahier des charges Miksprit — état d'implémentation

Mis à jour le 22 novembre 2026. Ce rapport décrit le code présent dans le dépôt; il ne vaut pas recette sur des comptes marchands ou du matériel réels.

## Diagnostic et livrables

| Exigence | État | Implémentation / limite connue |
| --- | --- | --- |
| A — Isolation tenant | Partielle, base renforcée | `TenantContext`, permissions et repositories tenant-scoped restent la base. Les nouveaux réglages paiement, abonnements, membres, commandes et webhooks sont rattachés au tenant. Scripts d'intégration couvrent plusieurs cas d'accès croisé, mais pas encore chaque endpoint, worker, export ou rôle en test HTTP. |
| B — Compte marchand propriétaire | Implémentée pour FedaPay | `TenantPaymentSettings`, `TenantPaymentProviderAdapter`, `FedaPayGateway`, `OwnerPaymentSettingsController` et `CreatePublicWifiCheckout`. Les secrets sont chiffrés et masqués; le webhook associe l'événement au marchand du tenant. FedaPay seul est intégré; Kkiapay n'est pas offert. |
| C — Aucune commission sur les nouveaux tickets | Implémentée | Le checkout et la confirmation de paiement tenant ne créent pas de reversement/commission pour les nouvelles ventes. Les écritures historiques et anciennes commandes de reversement sont conservées. Le script `verify-tenant-payment-settings.php` couvre le checkout et les secrets tenant; vérifier séparément les anciennes écritures avant toute suppression (aucune suppression n'est prévue). |
| D — Équipement | Partielle | Un seul routeur actif par site; la sélection ambiguë échoue. MikroTik RouterOS API over TLS valide le certificat, utilise des délais bornés et protège les identifiants. Les propriétaires saisissent/rotatent/révoquent leurs identifiants dans l'espace; ils sont chiffrés par tenant. `ROUTER_ALLOWED_HOSTS` impose une liste exacte avec filtrage egress à configurer au déploiement. Chaque nouvel hôte doit donc être approuvé par l'exploitant de la plateforme avant connexion: l'enrôlement n'est pas encore entièrement autonome. `miksprit:routers:sync-sessions` rapproche les sessions observées, compteurs, vouchers et fins de session en base; heures de début = première observation, et le cron doit être activé. Le contrat driver reste couplé aux types RouterOS; autres fabricants non prêts. Aucun vrai routeur testé. |
| E — Abonnement SaaS indépendant | Implémentée, offre non activée par défaut | `PlatformSubscriptionService`, webhook dédié, paiements séparés, offre configurable par admin et écrans propriétaire/admin. Aucun tarif ni essai n'est fourni. `PLATFORM_SUBSCRIPTIONS_REQUIRED=0` conserve la compatibilité des espaces existants; la restriction globale est activable par configuration. À l'expiration, l'historique reste consultable et les ventes/écritures opérationnelles sont bloquées. Seule la politique `read_only` est proposée. |
| F — Membres et administration | Implémentée au niveau fonctionnel | Invitations par e-mail, rôles, suspension/réactivation et contrôle des permissions propriétaires. Les opérations d'abonnement sont séparées de l'administration centrale des tenants. L'envoi réel d'e-mails et la matrice complète des permissions nécessitent une recette dans un environnement configuré. |
| G — Interfaces | Partielle | Console propriétaire: sites, routeurs, forfaits, commandes/paiements, paramètres marchand, membres et abonnement. Admin: tenants, opérations et offres/abonnements. Landing corrigée pour dire que le compte marchand appartient au fournisseur/exploitant, qu'il n'y a pas de commission de ticket et que le prix SaaS reste à valider. Le contact de démonstration n'est pas affiché tant que `NEXT_PUBLIC_CONTACT_EMAIL` n'est pas fourni. |
| H — Parcours complet | Partiel, scénarios simulés | Scripts d'intégration couvrent checkout tenant, webhook signé/idempotent, provisionnement/choix routeur et abonnements. La sandbox FedaPay, l'e-mail réellement délivré, un MikroTik accessible, le login Hotspot d'un client et l'expiration réelle doivent être recettés hors de cet environnement. |
| I — Connexion longue durée | Implémentée, recette réelle restante | JWT d'accès de 15 minutes et jeton opaque de renouvellement rotatif, hashé en base, avec expiration inactive de 14 jours et limite absolue de 30 jours. La migration `Version20261121170000` ajoute les tables de session. La concurrence entre plusieurs applications/onglets doit être couverte par une recette. |

## Migrations et retour arrière

- `Version20261120120000` et `Version20261120123000`: configuration de paiement tenant et contraintes de référence tenant.
- `Version20261120130000`: indicateur de configuration initiale du mot de passe d'un compte invité.
- `Version20261120140000`: offres, abonnements et historique de paiement SaaS isolé.
- `Version20261120150000`: identifiants de routeur chiffrés et isolés par tenant.
- `Version20261120160000`: username RouterOS sur les vouchers et sessions externes réutilisables.
- `Version20261121170000`: sessions de renouvellement révocables et jetons rotatifs.
- Les `down()` retirent les tables/colonnes introduites. Sauvegarder la base avant application; restaurer la sauvegarde pour un retour arrière avec données. Ne pas exécuter `down()` après des écritures métier sans export/plan de conservation.
- L'environnement local `db_miksprit` a été contrôlé à `Version20261121170000` le 21 novembre 2026. L'état de production est inconnu : vérifier `doctrine:migrations:status` sur la base cible et appliquer uniquement ses migrations absentes, après sauvegarde.

## Exploitation et dépendances

- Configurer `TENANT_PAYMENT_ENCRYPTION_KEY`, les secrets FedaPay et le retour HTTPS tenant; le propriétaire enregistre ses clés depuis sa console.
- Pour l'abonnement: `PLATFORM_SUBSCRIPTION_PROVIDER`, retour HTTPS, compte central FedaPay et notification séparée vers `/api/v1/webhooks/platform-subscriptions/fedapay`. Configurer les offres dans l'admin. Ne pas activer `PLATFORM_SUBSCRIPTIONS_REQUIRED` avant validation de l'offre et de la règle d'expiration.
- Configurer `ROUTER_ALLOWED_HOSTS` et le pare-feu egress avec la seule adresse de sortie applicative autorisée côté routeur; TLS public ou tunnel sortant/VPN avec certificat reconnu. Ne jamais exposer sans protection l'API administrative.
- Définir `ROUTER_CREDENTIAL_ENCRYPTION_KEY` de façon stable avant d'accepter les identifiants saisis par les exploitants; sa perte rend les mots de passe routeur enregistrés illisibles.
- Planifier et superviser chaque minute les commandes d'expiration des vouchers, traitement des paiements payés et `miksprit:subscriptions:refresh`. Le guide opérations détaille les sauvegardes locales et les limites de l'image Compose.
- `.github/workflows/ci.yml` définit sur push/PR le lint Symfony et les scripts d'intégration ainsi que TypeScript, ESLint et builds de production des trois applications. Vérifier son exécution réussie avant livraison; aucun résultat GitHub n'est attesté dans ce rapport.
- Il n'existe pas encore d'alerting, de sauvegarde chiffrée planifiée/off-site ni d'exercice de restauration automatisé dans le dépôt. Les scripts PowerShell locaux de dump/restauration existent; la planification, le chiffrement et la copie hors site relèvent de l'environnement d'exploitation. La mise en production demande une infrastructure et une validation explicite distinctes.

## Vérifications connues

Les contrôles consignés antérieurement comprennent `lint:container`, TypeScript/ESLint/build du client, le dry-run des migrations et des scripts d'intégration ciblés. La rotation de renouvellement dispose d'une preuve d'intégration ciblée et la migration `Version20261121170000` a été appliquée à la base locale. Ces contrôles ne remplacent pas la CI sur les changements finaux ni la recette HTTP, sandbox FedaPay, e-mail réel et routeur physique. Aucun paiement ou appareil réel n'a été contacté.

## Décisions produit encore nécessaires

1. Prix et périodicité d'abonnement, essai ou absence d'essai et date d'application obligatoire.
2. Politique de conservation après résiliation; la politique opérationnelle actuelle maintient l'historique en lecture seule.
3. Fournisseurs de paiement à proposer au lancement; seul FedaPay est actuellement développé.
4. Équipements à certifier, limites de sites/appareils/membres/tickets et éventuelles prestations de service.
5. Adresse de contact/démonstration à publier.

Tant que ces choix ne sont pas validés, le code conserve les valeurs commerciales configurables sans prix par défaut et ne force pas l'abonnement sur les espaces existants.

## Étude RADIUS — évolution ultérieure

Ne pas remplacer le driver direct MVP. Une étude de phase ultérieure devra sélectionner un contrôleur/matériel cible puis valider: RADIUS AAA (Access-Request/Accept/Reject, Accounting Start/Interim/Stop, CoA/Disconnect), attributs de durée/débit/volume réellement honorés par chaque modèle, secret partagé distinct par tenant ou proxy RADIUS, identifiants non réutilisables entre tenants, disponibilité/redondance, chiffrement des échanges (RadSec ou réseau privé), réconciliation des sessions et comportement lors des pannes. MikroTik Hotspot, UniFi et Omada devront être testés sur versions et contrôleurs précis; leur simple compatibilité théorique avec RADIUS ne constitue pas une certification Miksprit. Aucun fournisseur RADIUS ni équipement additionnel n'est déclaré pris en charge à ce stade.
