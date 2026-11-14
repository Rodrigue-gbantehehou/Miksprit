export type ApiResult<T> =
  | { state: "connected"; data: T; updatedAt: string }
  | { state: "unavailable"; message: string };

export type PlatformSummary = { tenants: number; activeUsers: number; activeRouters: number; paymentsTodayXof: number; pendingOutbox: number };
export type Tenant = { id: string; name: string; slug: string; status: string; createdAt: string };
export type Router = { id: string; name: string; tenantName: string; siteName: string; status: string; version?: string };
export type Payment = { id: string; reference: string; tenantName: string; amountXof: number; currency: string; status: string; createdAt: string };
export type LedgerEntry = { id: string; tenantName: string; entryType: string; amountXof: number; currency: string; createdAt: string };
export type AuditEvent = { id: string; actor: string; action: string; target: string; createdAt: string };
export type OperationalStatus = { name: string; state: string; observedAt: string };
export type HealthResponse = { status: string; service: string };
export type AdminUser = { id: string; email: string; active: boolean; roles: string[]; invitationPending: boolean };

const API_BASE = (process.env.NEXT_PUBLIC_API_BASE ?? "/api/v1").replace(/\/$/, "");
const LOGIN_URL = process.env.NEXT_PUBLIC_LANDING_LOGIN_URL ?? "http://localhost:3002/connexion";

function redirectToCentralLogin(): void {
  if (typeof window === "undefined") return;

  const login = new URL(LOGIN_URL, window.location.origin);
  login.searchParams.set("returnTo", `${window.location.origin}${window.location.pathname}`);
  window.location.assign(login.toString());
}

async function get<T>(path: string, signal?: AbortSignal): Promise<T> {
  const response = await fetch(`${API_BASE}${path}`, {
    method: "GET",
    headers: { Accept: "application/json" },
    credentials: "same-origin",
    cache: "no-store",
    signal,
  });
  if (response.status === 401 && path.replace(/\/$/, "") !== "/health") {
    redirectToCentralLogin();
    throw new Error("Votre session a expiré. Redirection vers la connexion centrale.");
  }
  if (!response.ok) {
    throw new Error(response.status === 403
      ? "Ce compte n’est pas autorisé à consulter ces données."
      : `L’API a répondu avec le statut ${response.status}.`);
  }
  return response.json() as Promise<T>;
}

async function write<T>(path: string, method: "POST" | "PATCH", payload: Record<string, unknown>): Promise<T> {
  const csrfResponse = await fetch(`${API_BASE}/auth/csrf`, { credentials: "same-origin", cache: "no-store", headers: { Accept: "application/json" } });
  if (!csrfResponse.ok) throw new Error("Impossible de vérifier la session administrateur.");
  const csrf = await csrfResponse.json() as { adminWriteCsrfToken?: string };
  if (!csrf.adminWriteCsrfToken) throw new Error("Jeton de sécurité absent. Rechargez la page.");
  const response = await fetch(`${API_BASE}${path}`, { method, credentials: "same-origin", cache: "no-store", headers: { Accept: "application/json", "Content-Type": "application/json" }, body: JSON.stringify({ ...payload, _csrf_token: csrf.adminWriteCsrfToken }) });
  if (response.status === 401) { redirectToCentralLogin(); throw new Error("Session expirée."); }
  const result = await response.json().catch(() => ({})) as T & { error?: string };
  if (response.status === 409 && path === "/admin/users") {
    throw new Error("Cet identifiant URL est déjà attribué à une autre organisation ou ce propriétaire est déjà lié à cet espace.");
  }
  if (!response.ok) throw new Error(result.error ?? `L’API a répondu avec le statut ${response.status}.`);
  return result;
}

export async function apiResource<T>(path: string, signal?: AbortSignal): Promise<ApiResult<T>> {
  try {
    const data = await get<T>(path, signal);
    return { state: "connected", data, updatedAt: new Date().toISOString() };
  } catch (error) {
    if (error instanceof DOMException && error.name === "AbortError") throw error;
    return {
      state: "unavailable",
      message: error instanceof Error ? error.message : "Impossible de joindre l’API.",
    };
  }
}

export const api = {
  health: (signal?: AbortSignal) => apiResource<HealthResponse>("/health", signal),
  summary: (signal?: AbortSignal) => apiResource<PlatformSummary>("/admin/summary", signal),
  tenants: (signal?: AbortSignal) => apiResource<Tenant[]>("/admin/tenants", signal),
  routers: (signal?: AbortSignal) => apiResource<Router[]>("/admin/routers", signal),
  payments: (signal?: AbortSignal) => apiResource<Payment[]>("/admin/payments", signal),
  ledger: (signal?: AbortSignal) => apiResource<LedgerEntry[]>("/admin/ledger", signal),
  audit: (signal?: AbortSignal) => apiResource<AuditEvent[]>("/admin/audit", signal),
  operations: (signal?: AbortSignal) => apiResource<OperationalStatus[]>("/admin/operations", signal),
  users: (signal?: AbortSignal) => apiResource<AdminUser[]>("/admin/users", signal),
  inviteOwner: (payload: { email: string; organizationName: string; slug: string }) => write<{ userId: string; message?: string }>('/admin/users', 'POST', payload),
  setUserActive: (id: string, active: boolean) => write<{ id: string; email: string; active: boolean }>(`/admin/users/${encodeURIComponent(id)}/status`, 'PATCH', { active }),
  resendInvitation: (id: string) => write<{ status: string }>(`/admin/users/${encodeURIComponent(id)}/invitation`, 'POST', {}),
};
