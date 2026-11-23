# Local database and operations baseline

## Start MySQL for development

1. Copy `.env.example` to `.env.local`.
2. Set `MYSQL_PASSWORD` and `MYSQL_ROOT_PASSWORD` to different, strong random values. Keep `.env.local` untracked and private.
3. From the repository root, run:

   ```powershell
   docker compose --env-file deploy/.env.local -f deploy/compose.yaml up -d
   docker compose --env-file deploy/.env.local -f deploy/compose.yaml ps
   ```

The database is published only on `127.0.0.1:3307` by default and persists in the named volume `miksprit_mysql_data`. Compose refuses to start when either password is missing. This file starts only MySQL; the Symfony API is still run separately from `api/`.

The application key belongs in `api/.env.local` (Symfony's local environment file), not `deploy/.env.local`. Generate it in PowerShell:

```powershell
php -r "echo base64_encode(random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES)), PHP_EOL;"
```

Paste the output into `api/.env.local` as `VOUCHER_CREDENTIAL_ENCRYPTION_KEY=<output>`. The API requires the key when it first constructs the voucher credential cipher; it intentionally has no fallback. Do not rotate it until existing voucher credentials have been re-encrypted with a versioned keyring.

Set a stable 32-byte `ROUTER_CREDENTIAL_ENCRYPTION_KEY` (raw or `base64:` encoded) in the API secret manager. Owners enter their own RouterOS API username/password during enrollment; Miksprit stores them encrypted per tenant. Set `ROUTER_ALLOWED_HOSTS` to the exact approved hostnames/IPs and configure matching egress restrictions. Set `ROUTEROS_HOTSPOT_PROFILE` to a profile that exists on every active RouterOS device. Never commit real credentials.

Set the API's local `DATABASE_URL` in `api/.env.local` to the MySQL user/database/port and URL-encoded password. Do not put a real password in `api/.env` or commit local environment files.

The committed API `.env` deliberately keeps FedaPay disabled. For a sandbox, set `FEDAPAY_ENABLED=1`, `FEDAPAY_SECRET_KEY`, and the endpoint-specific `FEDAPAY_WEBHOOK_SECRET` in `api/.env.local`. Keep the sandbox API base URL at `https://sandbox-api.fedapay.com/v1` and set `PAYMENT_RETURN_URL` to the HTTPS client URL ending in `/portal`; never use a secret key in frontend code. Configure `POST https://<api-host>/api/v1/webhooks/fedapay` in the FedaPay dashboard. Live payments require production credentials and live URLs.

For production scheduling, copy `miksprit.cron.example` to `miksprit.cron`, replace the sample paths and PHP binary, provision writable lock/log directories, then install it under the API service account with `crontab miksprit.cron`. The wrapper `run-scheduled-task.sh` locks each task and writes its output to the configured log directory. See [`../docs/deployment/operations.md`](../docs/deployment/operations.md) for the schedule and monitoring requirements. Monitor `payment_outbox`: `pending` means waiting for the command, `failed` needs operator action, and `processing` means RouterOS reconciliation is required before any retry. The expiry command reports overdue vouchers without a known router; reconcile those before considering the expiry queue clean.

## Backup, restore, and monitoring

PowerShell scripts are available: `./deploy/backup.ps1` creates a dated dump outside the repository, and `./deploy/restore.ps1 -BackupFile <path>` requires explicit confirmation before restoring. See [`docs/deployment/operations.md`](../docs/deployment/operations.md) for security handling, recovery checks, and the current supervision limits. A named Docker volume is persistence, not a backup.
