# MikroTik RouterOS

Miksprit talks to RouterOS through its binary API over TLS (`api-ssl`, normally TCP 8729). The transport verifies both the certificate chain and the router hostname and rejects self-signed certificates. Install a certificate issued by a CA trusted by the application host (or add the private CA to the host trust store); do not disable verification. Limit the router firewall to the application egress IP and a dedicated RouterOS API user with only the permissions required for Hotspot user/session operations.

The PHP adapter supports RouterOS 6.43+ login (`/login =name=… =password=…`) and RouterOS v7. Plain TCP RouterOS API is intentionally unsupported. The connection and operation timeouts are bounded. A `!trap` is a definite command rejection. Socket/TLS/protocol failure after connection is treated as an unknown outcome and must be reconciled before retry. A failure to establish the TLS socket occurs before any API command is sent.

The adapter implements:

- `POST /ip/hotspot/user/add` with separate API words for voucher name, password, profile, and optional `limit-uptime`;
- exact-name Hotspot user lookup and removal for revocation;
- `/system/resource/print` and `/system/identity/print` for health;
- `/ip/hotspot/active/print` for active session and accounting snapshots, preserving unavailable byte counters as `null`;
- active session disconnection by validated RouterOS `.id`.

Router credentials remain outside the Router entity. Its `credential_reference` is read through a tenant-scoped repository projection. `TenantRouterCredentialSettings` resolves the matching encrypted database record using both the tenant ID and its reference just before the operation. Resolved credentials live only in memory, are non-serializable and redact debug output. Voucher credentials use authenticated Sodium encryption and are never put in logs or jobs as plaintext.

Owners now enter the RouterOS API username and password in the router enrollment form. The API encrypts them with Sodium secretbox into `tenant_router_credentials`; the credentials are never returned to the browser. Configure a stable, random 32-byte `ROUTER_CREDENTIAL_ENCRYPTION_KEY` (raw or `base64:` encoded) in the deployment secret manager before applying the migration and enabling router operations. The owner can replace credentials only after suspending an active router, then run health verification again; revocation also requires the router to be suspended. Restrict outbound API TLS access at the host/network firewall to approved RouterOS addresses before enabling health checks or provisioning.

Owner enrollment records a tenant-owned router in `pending_enrollment` and performs no network connection. Certificate provisioning, safe address allowlisting/egress policy, active session reconciliation into persisted `HotspotSession` records, and a live router test are still required. An active session snapshot is not by itself a persisted accounting event.

For the MVP, a site may have only one active router. Additional routers may be enrolled, but the owner must suspend the active router before activating another. The API enforces this during the verified activation flow; paid-order provisioning also refuses to choose a router if legacy data contains more than one active router for the site. Multi-router selection by plan or policy remains future work.
