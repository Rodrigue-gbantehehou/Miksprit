# Vitrine Miksprit et connexion centrale

`landing/` contient la vitrine produit et l’unique page de connexion (`/connexion`). Le formulaire `/inscription` crée le compte opérateur seul; la connexion est immédiate et aucune vérification par courriel ne la bloque. Après connexion, `client/` propose au propriétaire de créer son espace Miksprit, puis il peut y créer ses sites. Les apps `client/` et `admin/` ne dupliquent pas le formulaire de connexion : quand l’API répond 401, elles redirigent vers cette page.

## Développement local

```powershell
npm install
$env:API_INTERNAL_BASE="http://127.0.0.1:8000"
$env:NEXT_PUBLIC_API_BASE="/api/v1"
$env:NEXT_PUBLIC_CLIENT_APP_URL="http://localhost:3000"
$env:NEXT_PUBLIC_ADMIN_APP_URL="http://localhost:3001"
npm run dev
```

La vitrine et la connexion centrale sont servies sur `http://localhost:3002`; le parcours de création de compte est à `http://localhost:3002/inscription`. L'application propriétaire `client/` utilise le port 3000 et `admin/` le port 3001.

En production, remplacer `NEXT_PUBLIC_CLIENT_APP_URL` et `NEXT_PUBLIC_ADMIN_APP_URL` par les origines HTTPS réellement déployées avant le build Next.js. Si elles manquent, la page ne renvoie plus l'utilisateur vers localhost et affiche une erreur de configuration. Après connexion, un compte sans espace est envoyé vers la console propriétaire afin de créer son premier espace.

Les variables `NEXT_PUBLIC_*` ne contiennent aucun secret. Le navigateur appelle l’API via le proxy Next same-origin, qui transmet `/api/v1/*` à `API_INTERNAL_BASE`.

## Contrat d’authentification

- `GET /api/v1/auth/csrf` fournit un jeton CSRF signé et le pose dans un cookie `HttpOnly`; les mutations le renvoient en `_csrf_token`.
- `POST /api/v1/auth/login` reçoit `{ email, password, _csrf_token }`, puis renvoie l’identité minimale et pose un JWT d’accès dans le cookie `MIKSPRIT_ACCESS` (`HttpOnly`, `SameSite=Lax`, durée 15 minutes).
- `GET /api/v1/auth/me` fournit l’identité liée au JWT courant.
- `POST /api/v1/auth/logout` invalide le JWT courant via la blocklist et efface les cookies, avec vérification CSRF.
- `POST /api/v1/auth/password/request` répond sans révéler si le compte existe; la demande est limitée à 5 par 15 minutes.
- `POST /api/v1/auth/password/reset` consomme un lien d'invitation ou de réinitialisation à usage unique; mot de passe minimum 12 caractères.

Le mailer Symfony utilise `MAILER_DSN` et `MAILER_FROM`; l'origine des liens est définie dans `AUTH_PUBLIC_BASE_URL`. Configure ces variables avec ton fournisseur pour la réinitialisation du mot de passe; elles ne conditionnent pas l'inscription ni la connexion initiale. Les clés RSA de développement sont dans `api/config/jwt/` et ignorées par Git; génère une paire dédiée et définis sa passphrase hors dépôt en production. Définis les valeurs secrètes dans `api/.env.local` ou le gestionnaire de secrets de déploiement; le `.env` du dépôt ne doit contenir que des valeurs d'exemple. Le parcours d'inscription est disponible sur `/inscription`; aucune authentification n'est simulée et aucun JWT n'est conservé dans `localStorage`.

L’inscription valide l’adresse e-mail et le mot de passe, puis crée un compte actif sans organisation. Après authentification, le propriétaire crée son espace via `POST /api/v1/owner/organizations`; il peut ensuite ajouter ses sites depuis `client/`. Le paramètre `returnTo` n’est accepté que si son origine correspond exactement à `NEXT_PUBLIC_CLIENT_APP_URL` ou `NEXT_PUBLIC_ADMIN_APP_URL` et que l’API autorise cet espace; un compte sans espace est redirigé vers `client/` pour démarrer.

## Déploiement

Les apps doivent rester sur le même site de confiance : héberger les apps sous un même hôte via reverse proxy, ou sous des sous-domaines contrôlés et configurer `AUTH_COOKIE_DOMAIN` sur leur domaine parent exact. En production, les cookies sont `Secure`, `HttpOnly`, `SameSite=Lax` et `Path=/`. Éviter les domaines wildcard. Si les apps sont sur des domaines sans relation, utiliser un flux SSO standard (OIDC). Le JWT expire après 15 minutes. Le cookie de renouvellement opaque est rotatif (14 jours d'inactivité, 30 jours maximum); appliquer `Version20261121170000`. Déconnexion, réinitialisation du mot de passe et désactivation révoquent les sessions.
