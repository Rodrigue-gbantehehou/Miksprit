# Déployer les frontends Miksprit chez O2switch

## Domaines

| Application | Domaine |
| --- | --- |
| API Symfony | `mikapi.poulvia.com` |
| Vitrine, connexion et inscription | `miksprit.poulvia.com` |
| Espace gérant Wi-Fi | `mymik.poulvia.com` |
| Administration Miksprit | `mikhome.poulvia.com` |

Chaque frontend est une application Node.js indépendante gérée par **Setup Node.js App** dans cPanel. Les scripts `server.js` démarrent Next.js sur le socket Passenger lorsqu’ils sont lancés par Passenger; `npm start` conserve un port local de développement de production.

## Préparer chaque application dans cPanel

1. Faire pointer les quatre sous-domaines vers l’hébergement et activer leur certificat HTTPS.
2. Cloner le dépôt dans un répertoire privé du compte, par exemple `~/apps/Miksprit`. Ne pas placer le code dans `public_html` ni dans le document root d’un domaine.
3. Créer une application Node.js pour chaque frontend avec Node.js **22**, le mode **Production**, son dossier d’application (`landing`, `client` ou `admin`), son URL de sous-domaine à la racine et son fichier de démarrage `server.js`.
4. Installer les dépendances dans le dossier de l’application avec `npm ci`.
5. Ajouter les variables d’environnement indiquées ci-dessous dans cPanel pour l’exécution. Les exporter aussi dans la session SSH qui lance le build : les variables `NEXT_PUBLIC_*` sont compilées dans le navigateur et `API_INTERNAL_BASE` est lu lors de la création des règles Next.js.
6. Lancer `npm run build` dans chaque dossier. Redémarrer chaque application depuis cPanel après le build.
7. Pour les mises à jour, récupérer les changements, refaire `npm ci` si le lockfile a changé, reconstruire, puis redémarrer l’application.

Les dossiers `landing`, `client` et `admin` ont chacun leur propre `package-lock.json` et `server.js`. Ne lance pas `npm install` depuis la racine du monorepo.

## Variables Passenger

Toutes les applications utilisent un proxy Next.js de même origine pour `/api/v1/*`. Le navigateur garde donc `NEXT_PUBLIC_API_BASE=/api/v1`; le serveur Next transmet la requête à l’API.

### `landing` — `miksprit.poulvia.com`

```dotenv
NODE_ENV=production
NEXT_PUBLIC_API_BASE=/api/v1
API_INTERNAL_BASE=https://mikapi.poulvia.com
NEXT_PUBLIC_CLIENT_APP_URL=https://mymik.poulvia.com
NEXT_PUBLIC_ADMIN_APP_URL=https://mikhome.poulvia.com
```

### `client` — `mymik.poulvia.com`

```dotenv
NODE_ENV=production
NEXT_PUBLIC_API_BASE=/api/v1
API_INTERNAL_BASE=https://mikapi.poulvia.com
NEXT_PUBLIC_LANDING_LOGIN_URL=https://miksprit.poulvia.com/connexion
```

### `admin` — `mikhome.poulvia.com`

```dotenv
NODE_ENV=production
NEXT_PUBLIC_API_BASE=/api/v1
API_INTERNAL_BASE=https://mikapi.poulvia.com
NEXT_PUBLIC_LANDING_LOGIN_URL=https://miksprit.poulvia.com/connexion
```

`API_INTERNAL_BASE` est utilisé dans les règles de réécriture Next.js générées pendant le build. Après sa modification, reconstruire l’application et la redémarrer. Les variables `NEXT_PUBLIC_*` sont intégrées au bundle navigateur au build. Configure les mêmes valeurs dans cPanel et dans la session SSH de compilation; ne mets aucun secret dans une variable `NEXT_PUBLIC_*`.

## Configuration API requise pour ces domaines

L’API Symfony n’est pas une application Node.js Passenger. Elle doit être déployée séparément sur `mikapi.poulvia.com` avec PHP et sa configuration de production. Définir au minimum :

```dotenv
APP_ENV=prod
DEFAULT_URI=https://mikapi.poulvia.com
AUTH_PUBLIC_BASE_URL=https://miksprit.poulvia.com
AUTH_COOKIE_DOMAIN=poulvia.com
PAYMENT_RETURN_URL=https://mymik.poulvia.com/portal
PLATFORM_SUBSCRIPTION_RETURN_URL=https://mymik.poulvia.com/abonnement
```

`AUTH_COOKIE_DOMAIN=poulvia.com` permet aux cookies d’authentification et CSRF, `Secure`, `HttpOnly` et `SameSite=Lax`, de rester disponibles entre les sous-domaines. Ne configure pas ce domaine sur un environnement local ou partagé avec un autre produit.

L’API doit répondre en HTTPS sur `https://mikapi.poulvia.com/api/v1`. Il faut aussi configurer ses secrets de production, la base de données, le mail, les clés de chiffrement et les tâches planifiées selon [`../README.md`](../README.md) et [`../../docs/deployment/operations.md`](../../docs/deployment/operations.md).

## Ordre de mise en ligne

1. Déployer et vérifier d’abord l’API `mikapi`.
2. Déployer `miksprit`, puis vérifier inscription, e-mail de confirmation, connexion et liens de réinitialisation.
3. Déployer `mymik` et `mikhome`, puis vérifier les redirections de connexion/déconnexion et l’accès authentifié depuis les deux sous-domaines.

Passenger fournit le routage HTTP et relance les processus Node.js. Il ne configure pas les domaines DNS, les certificats, l’API Symfony, les cookies entre sous-domaines ou les variables de production à votre place.
