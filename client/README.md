# Miksprit Client

Les règles UI obligatoires et les composants shadcn sont définis dans [`docs/architecture/design-system.md`](../docs/architecture/design-system.md) et `components/ui/`.

Application Next.js de l’espace propriétaire et portail captif public. Le portail est servi statiquement à `/portal/`; la console propriétaire est la route `/`. L’authentification est centralisée dans `landing/connexion`; cette app n’a pas de formulaire de connexion.

## Lancer

```powershell
npm install
$env:API_INTERNAL_BASE="http://127.0.0.1:8000"
$env:NEXT_PUBLIC_API_BASE="/api/v1"
$env:NEXT_PUBLIC_LANDING_LOGIN_URL="http://localhost:3002/connexion"
npm run dev
```

Next.js proxyfie `/api/v1/*` vers `API_INTERNAL_BASE` côté serveur. Gardez `NEXT_PUBLIC_API_BASE` à la valeur même origine `/api/v1`, et ne configurez pas une URL d'API cross-origin dans le navigateur : cela évite d'exposer un hôte interne et réduit les besoins CORS. `API_INTERNAL_BASE` est une configuration serveur; elle ne doit pas contenir de secret. La console propriétaire et le portail n’affichent que les données retournées par l’API; les écrans restent indisponibles si les endpoints ou l’authentification ne sont pas configurés.

## API et diagnostic de connexion

Les routes de santé, catalogue, checkout, statut de commande, webhook, authentification et console propriétaire sont déclarées dans `api/`. Démarrez d'abord Symfony sur le port 8000, puis Next.js. Le proxy Next réécrit `/api/v1/*` vers `API_INTERNAL_BASE` (par défaut `http://127.0.0.1:8000`); redémarrez Next.js après toute modification de cette variable ou de `next.config.ts`.

```powershell
cd C:\projets\Miksprit\api
symfony server:start --port=8000

# Dans un autre terminal
cd C:\projets\Miksprit\client
$env:API_INTERNAL_BASE="http://127.0.0.1:8000"
npm run dev
```

Si `/api/v1/health` renvoie 500, consultez la sortie du serveur Symfony et `api/var/log/dev.log` : ce contrôleur ne dépend pas de la base de données, donc un 500 indique généralement une erreur de démarrage/configuration/runtime ou un autre processus répondant sur le port. Si une route propriétaire renvoie 404, vérifiez que le backend du port 8000 est bien cette version (`cd api; php bin/console debug:router api_owner_site_create`) et que Next pointe sur le bon port. Les mutations propriétaire nécessitent ensuite une session JWT valide et un jeton CSRF.

Quand le compte connecté n’a encore aucun espace actif, la console affiche le formulaire « Créer mon espace Miksprit ». Le compte connecté crée son organisation et reçoit immédiatement le rôle propriétaire; aucune invitation n’est nécessaire. Les liens d’invitation restent réservés aux nouveaux comptes dont le mot de passe doit être défini. Un compte super-administrateur ne reçoit pas automatiquement un espace, mais peut en créer un comme tout autre compte authentifié.

## Routes

- `/` : console propriétaire (dashboard, sites, forfaits, routeurs, transactions).
- `/portal/` : portail captif HTML/CSS/JS servi depuis `public/portal/`.

Le portail transmet `?siteId=<id>` à sa page statique, consomme le catalogue public, crée le checkout via `POST /api/v1/public/sites/{siteId}/checkout` et interroge `GET /api/v1/public/orders/{reference}/status`. Aucun retour navigateur ne confirme un paiement; les identifiants apparaissent après confirmation et provisioning.

La console propriétaire attend les routes `/owner/*` décrites dans le client API. L’authentification et l’isolation du tenant doivent être assurées côté serveur; aucun `tenant_id` client n’est utilisé comme autorité.

Si une route protégée répond 401, la console redirige vers le login central avec le chemin courant. Le landing valide le retour contre l’origine autorisée et les espaces accordés par l’API. Une réponse 403 reste un refus d’accès et ne déclenche pas de nouvelle connexion.
