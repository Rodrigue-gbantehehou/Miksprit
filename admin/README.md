# Administration globale Miksprit

Les règles UI obligatoires et les composants shadcn sont définis dans [`docs/architecture/design-system.md`](../docs/architecture/design-system.md) et `components/ui/`.

Console responsive Next.js réservée aux super-administrateurs de la plateforme. Elle ne donne pas accès aux flux propriétaires. La connexion est centralisée dans `landing/connexion`; cette app n’a pas de formulaire de connexion. Les données sont exclusivement chargées depuis l’API configurée; aucun chiffre de démonstration ou succès local n’est affiché. Tant que les endpoints métier n’existent pas, chaque section montre clairement l’état indisponible et permet de recharger.

## Développement

```powershell
npm install
$env:API_INTERNAL_BASE="http://127.0.0.1:8000"
$env:NEXT_PUBLIC_API_BASE="/api/v1"
$env:NEXT_PUBLIC_LANDING_LOGIN_URL="http://localhost:3002/connexion"
npm run dev
```

Next.js proxyfie `/api/v1/*` vers `API_INTERNAL_BASE` côté serveur. Gardez `NEXT_PUBLIC_API_BASE` à `/api/v1` (même origine); ne mettez pas d'URL cross-origin dans cette variable publique. Cela évite les appels directs du navigateur à l'API et les exigences CORS. `API_INTERNAL_BASE` est une variable serveur sans secret. L’API doit appliquer une authentification globale de super-administrateur distincte du scope tenant propriétaire.

## API réellement disponible

Le backend expose `/api/v1/admin/*` pour tenants, routeurs, paiements, ledger, audit, opérations et comptes. Ces routes exigent `ROLE_SUPER_ADMIN`; les écritures demandent le jeton CSRF `admin_write`. La page Comptes permet d'inviter un propriétaire et d'activer/désactiver un compte. Les autres sections affichent uniquement les données renvoyées par l'API, sans chiffres de démonstration. Les migrations doivent être appliquées avant l'utilisation de ces routes.

## Endpoints attendus (à implémenter côté API)

- `GET /admin/summary` → résumé agrégé global, sans données d'exemple.
- `GET /admin/tenants` → organisations tenant.
- `GET /admin/routers` → inventaire global des routeurs; aucun credential retourné.
- `GET /admin/payments` → transactions et état webhook.
- `GET /admin/ledger` → écritures comptables sans déclencher de reversement.
- `GET /admin/audit` → journal d'audit central.
- `GET /admin/operations` → état des workers et dépendances d'exploitation.

Les réponses sont requêtées sans cache. Aucune information de secret routeur, secret PSP ou code voucher n’est affichée dans cette console. Tous les endpoints exigent une session super-admin et doivent répondre 401/403 hors autorisation; les actions centrales sont auditées côté serveur.

Une réponse 401 redirige vers la page unique de connexion du landing avec le chemin courant. Le landing n’accepte le retour que pour l’origine admin explicitement configurée et un compte ayant les droits admin. Un 403 ne provoque pas de redirection.
