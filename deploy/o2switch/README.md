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
4. Entrer dans le dossier précis de l’application et installer toutes ses dépendances avec `npm ci` (ne pas utiliser `npx next`, qui peut télécharger Next seul sans React ni les alias locaux du projet).
5. Ajouter les variables d’environnement indiquées ci-dessous dans cPanel pour l’exécution. Les exporter aussi dans la session SSH qui lance le build : les variables `NEXT_PUBLIC_*` sont compilées dans le navigateur et `API_INTERNAL_BASE` est lu lors de la création des règles Next.js.
6. Lancer `npm run build` dans chaque dossier. Le script utilise Webpack pour éviter l’échec du binaire Turbopack/SWC sur les systèmes O2switch dont la glibc est antérieure à 2.29. Redémarrer chaque application depuis cPanel après le build.
7. Pour les mises à jour, récupérer les changements, refaire `npm ci` si le lockfile a changé, reconstruire, puis redémarrer l’application.

Les dossiers `landing`, `client` et `admin` ont chacun leur propre `package-lock.json` et `server.js`. Exemple après s’être placé dans le dossier `landing` :

```sh
npm ci
npm run build
```

Reprendre ces commandes séparément depuis `client` puis `admin`. Ne lance pas `npm install` depuis la racine du monorepo et ne lance pas `npx next build`.

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

`API_INTERNAL_BASE` est utilisé dans les règles de réécriture Next.js générées pendant le build. En production, son défaut est `https://mikapi.poulvia.com`; les redirections vers la connexion et les espaces utilisent aussi les domaines ci-dessus par défaut. Si tu remplaces une de ces valeurs dans cPanel, exporte-la également dans la session SSH avant `npm run build`, puis redémarre l’application. Les variables `NEXT_PUBLIC_*` sont intégrées au bundle navigateur au build. Ne mets aucun secret dans une variable `NEXT_PUBLIC_*`.

## Configuration API requise pour ces domaines

L’API Symfony n’est pas une application Node.js Passenger. Elle doit être déployée séparément sur `mikapi.poulvia.com` avec PHP et sa configuration de production. Définir au minimum :

```dotenv
APP_ENV=prod
APP_SECRET=<secret aléatoire stable d’au moins 32 octets>
DEFAULT_URI=https://mikapi.poulvia.com
AUTH_PUBLIC_BASE_URL=https://miksprit.poulvia.com
AUTH_COOKIE_DOMAIN=poulvia.com
PAYMENT_RETURN_URL=https://mymik.poulvia.com/portal
PLATFORM_SUBSCRIPTION_RETURN_URL=https://mymik.poulvia.com/abonnement
```

Générer `APP_SECRET` une seule fois avec `php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'`, puis enregistrer la valeur dans l’environnement de production de l’application PHP (pas dans Git). `RefreshSessionStore`, utilisé lors de la connexion, exige au moins 32 octets ; la valeur de développement dans `.env` ne convient pas à la production. Conserver la même valeur lors des déploiements suivants.

`AUTH_COOKIE_DOMAIN=poulvia.com` permet aux cookies d’authentification et CSRF, `Secure`, `HttpOnly` et `SameSite=Lax`, de rester disponibles entre les sous-domaines. Ne configure pas ce domaine sur un environnement local ou partagé avec un autre produit.

Le document root Apache de `mikapi.poulvia.com` doit être `Miksprit/api/public`, où `.htaccess` transmet les routes inconnues à `index.php`. Apache doit autoriser les règles `.htaccess` (`AllowOverride All`) et `mod_rewrite` doit être actif. L’API doit alors répondre en HTTPS sur `https://mikapi.poulvia.com/api/v1` sans préfixe `index.php`.

Les clés JWT ne sont pas versionnées. Sur le serveur, depuis `api/`, crée le répertoire si nécessaire, configure `JWT_PASSPHRASE` dans l’environnement de production, puis génère la paire une seule fois :

```sh
mkdir -p config/jwt
php bin/console lexik:jwt:generate-keypair
chmod 600 config/jwt/private.pem
chmod 644 config/jwt/public.pem
php bin/console lexik:jwt:check-config
```

Garde `private.pem` hors de Git et préserve la même paire lors des déploiements suivants. `JWT_SECRET_KEY` et `JWT_PUBLIC_KEY` doivent pointer sur ces fichiers, et `JWT_PASSPHRASE` doit être exactement celle utilisée à la génération. Il faut aussi configurer les autres secrets de production, la base de données, le mail, les clés de chiffrement et les tâches planifiées selon [`../README.md`](../README.md) et [`../../docs/deployment/operations.md`](../../docs/deployment/operations.md).

## Ordre de mise en ligne

1. Déployer et vérifier d’abord l’API `mikapi`.
2. Déployer `miksprit`, puis vérifier inscription, e-mail de confirmation, connexion et liens de réinitialisation.
3. Déployer `mymik` et `mikhome`, puis vérifier les redirections de connexion/déconnexion et l’accès authentifié depuis les deux sous-domaines.

Passenger fournit le routage HTTP et relance les processus Node.js. Il ne configure pas les domaines DNS, les certificats, l’API Symfony, les cookies entre sous-domaines ou les variables de production à votre place.
