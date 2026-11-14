# Vitrine Miksprit et connexion centrale

`landing/` contient la vitrine produit et l’unique page de connexion (`/connexion`). Les opérateurs peuvent ouvrir un espace depuis `/inscription`; l'organisation et le rôle Owner sont créés après vérification du courriel via `/verifier-compte`. Les apps `client/` et `admin/` ne dupliquent pas le formulaire de connexion : quand l’API répond 401, elles redirigent vers cette page.

## Développement local

```powershell
npm install
$env:API_INTERNAL_BASE="http://127.0.0.1:8000"
$env:NEXT_PUBLIC_API_BASE="/api/v1"
$env:NEXT_PUBLIC_CLIENT_APP_URL="http://localhost:3000"
$env:NEXT_PUBLIC_ADMIN_APP_URL="http://localhost:3001"
npm run dev -- --port 3002
```

Les variables `NEXT_PUBLIC_*` ne contiennent aucun secret. Le navigateur appelle l’API via le proxy Next same-origin, qui transmet `/api/v1/*` à `API_INTERNAL_BASE`.

## Contrat d’authentification

- `GET /api/v1/auth/csrf` fournit un jeton CSRF signé et le pose dans un cookie `HttpOnly`; les mutations le renvoient en `_csrf_token`.
- `POST /api/v1/auth/login` reçoit `{ email, password, _csrf_token }`, puis renvoie l’identité minimale et pose un JWT d’accès dans le cookie `MIKSPRIT_ACCESS` (`HttpOnly`, `SameSite=Lax`, durée 15 minutes).
- `GET /api/v1/auth/me` fournit l’identité liée au JWT courant.
- `POST /api/v1/auth/logout` invalide le JWT courant via la blocklist et efface les cookies, avec vérification CSRF.
- `POST /api/v1/auth/password/request` répond sans révéler si le compte existe; la demande est limitée à 5 par 15 minutes.
- `POST /api/v1/auth/password/reset` consomme un lien d'invitation ou de réinitialisation à usage unique; mot de passe minimum 12 caractères.

Le mailer Symfony utilise `MAILER_DSN` et `MAILER_FROM`; l'origine des liens est définie dans `AUTH_PUBLIC_BASE_URL`. Configure ces variables avec ton fournisseur. La valeur de développement `null://null` n'envoie aucun message : l'inscription ne pourra pas être finalisée tant qu'un transport mail réel n'est pas configuré. Les clés RSA de développement sont dans `api/config/jwt/` et ignorées par Git; génère une paire dédiée et définis sa passphrase hors dépôt en production. Définis les valeurs secrètes dans `api/.env.local` ou le gestionnaire de secrets de déploiement; le `.env` du dépôt ne doit contenir que des valeurs d'exemple. Le parcours d'inscription vérifié est disponible sur `/inscription`; aucune authentification n'est simulée et aucun JWT n'est conservé dans `localStorage`.

L’inscription répond de façon générique afin de ne pas révéler les comptes existants. Les liens de vérification sont à usage unique et expirent après une heure. Le mot de passe est haché avant stockage du dossier de vérification; aucun utilisateur, organisation ou rôle n’est créé avant la vérification. Le paramètre `returnTo` n’est accepté que si son origine correspond exactement à `NEXT_PUBLIC_CLIENT_APP_URL` ou `NEXT_PUBLIC_ADMIN_APP_URL` et que l’API autorise cet espace.

## Déploiement

Les apps doivent rester sur le même site de confiance : héberger les apps sous un même hôte via reverse proxy, ou sous des sous-domaines contrôlés et configurer `AUTH_COOKIE_DOMAIN` sur leur domaine parent exact. En production les cookies sont `Secure`; garder aussi `HttpOnly`, `SameSite=Lax` et `Path=/`. Ne jamais utiliser un domaine wildcard trop large. Si les apps sont sur des domaines sans relation, utiliser un flux SSO standard (OIDC). Le JWT d'accès expire après 15 minutes; le renouvellement automatique par refresh token reste à mettre en place.
