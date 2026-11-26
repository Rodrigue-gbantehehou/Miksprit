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
export type AdminUser = { id: string; email: string; active: boolean; roles: string[] };
export type SubscriptionOffer = { id: string; code: string; name: string; provider: string; amountXof: number; intervalDays: number; graceDays: number; expiredAccessPolicy: string; active: boolean };
export type PlatformSubscription = { id: string; tenantId: string; organization: string; status: string; offer: string; amountXof: number; currentPeriodEnd: string | null; nextDueAt: string | null };

const API_BASE = (process.env.NEXT_PUBLIC_API_BASE ?? "/api/v1").replace(/\/$/, "");
const DEFAULT_CENTRAL_LOGIN_URL = process.env.NODE_ENV === "development" ? "http://localhost:3002/connexion" : "https://miksprit.poulvia.com/connexion";
const configuredLoginUrl = process.env.NEXT_PUBLIC_LANDING_LOGIN_URL?.trim();
export const CENTRAL_LOGIN_URL = configuredLoginUrl && /^https?:\/\//i.test(configuredLoginUrl) ? configuredLoginUrl : DEFAULT_CENTRAL_LOGIN_URL;

let refreshTimer: number | null = null;
let refreshInFlight: Promise<boolean> | null = null;
let lastRefreshAt = 0;

async function refreshSession(): Promise<boolean> {
  if (typeof window === "undefined") return false;
  if (refreshInFlight) return refreshInFlight;
  refreshInFlight = (async () => {
    try {
      const csrfResponse = await fetch(`${API_BASE}/auth/csrf`, { credentials: "same-origin", cache: "no-store", headers: { Accept: "application/json" } });
      if (!csrfResponse.ok) return false;
      const csrf = await csrfResponse.json() as { csrfToken?: string };
      if (!csrf.csrfToken) return false;
      const response = await fetch(`${API_BASE}/auth/refresh`, { method: "POST", credentials: "same-origin", cache: "no-store", headers: { Accept: "application/json", "Content-Type": "application/json" }, body: JSON.stringify({ _csrf_token: csrf.csrfToken }) });
      if (!response.ok) return false;
      lastRefreshAt = Date.now();
      return true;
    } catch { return false; }
    finally { refreshInFlight = null; }
  })();
  return refreshInFlight;
}

function scheduleSessionRefresh(): void {
  if (typeof window === "undefined" || refreshTimer !== null) return;
  const refreshIfVisible = () => {
    if (document.visibilityState === "visible" && Date.now() - lastRefreshAt >= 10 * 60 * 1000) void refreshSession();
  };
  refreshTimer = window.setInterval(refreshIfVisible, 60 * 1000);
  document.addEventListener("visibilitychange", refreshIfVisible);
  window.setTimeout(refreshIfVisible, 1000);
}

function redirectToCentralLogin(): void {
  if (typeof window === "undefined") return;

  const login = new URL(CENTRAL_LOGIN_URL, window.location.origin);
  login.searchParams.set("returnTo", `${window.location.origin}${window.location.pathname}`);
  window.location.assign(login.toString());
}

async function get<T>(path: string, signal?: AbortSignal): Promise<T> {
  scheduleSessionRefresh();
  const headers: Record<string, string> = { Accept: "application/json" };
  const response = await fetch(`${API_BASE}${path}`, {
    method: "GET",
    headers,
    credentials: "same-origin",
    cache: "no-store",
    signal,
  });
  if (response.status === 401 && path.replace(/\/$/, "") !== "/health") {
    if (await refreshSession()) {
      const retry = await fetch(`${API_BASE}${path}`, { method: "GET", headers, credentials: "same-origin", cache: "no-store", signal });
      if (retry.ok) return retry.json() as Promise<T>;
      if (retry.status !== 401) throw new Error(`Erreur ${retry.status}.`);
    }
    redirectToCentralLogin();
    throw new Error("Votre session a expiré.");
  }
  if (!response.ok) {
    throw new Error(response.status === 403
      ? "Accès refusé."
      : `Erreur ${response.status}.`);
  }
  return response.json() as Promise<T>;
}

async function write<T>(path: string, method: "POST" | "PATCH", payload: Record<string, unknown>): Promise<T> {
  scheduleSessionRefresh();
  const csrfResponse = await fetch(`${API_BASE}/auth/csrf`, { credentials: "same-origin", cache: "no-store", headers: { Accept: "application/json" } });
  if (!csrfResponse.ok) throw new Error("Vérification de votre session impossible. Rechargez la page puis réessayez.");
  const csrf = await csrfResponse.json() as { adminWriteCsrfToken?: string };
  if (!csrf.adminWriteCsrfToken) throw new Error("Votre session de sécurité a expiré. Rechargez la page.");
  const response = await fetch(`${API_BASE}${path}`, { method, credentials: "same-origin", cache: "no-store", headers: { Accept: "application/json", "Content-Type": "application/json" }, body: JSON.stringify({ ...payload, _csrf_token: csrf.adminWriteCsrfToken }) });
  if (response.status === 401) {
    if (await refreshSession()) throw new Error("Session rétablie. Renvoyez le formulaire.");
    redirectToCentralLogin();
    throw new Error("Session expirée.");
  }
  const result = await response.json().catch(() => ({})) as T & { error?: string };
  if (!response.ok) throw new Error(result.error ?? `Erreur ${response.status}.`);
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
      message: error instanceof Error ? error.message : "Connexion impossible.",
    };
  }
}

export const api = {
  logout: async (): Promise<void> => {
    const csrfResponse = await fetch(`${API_BASE}/auth/csrf`, { credentials: "same-origin", cache: "no-store", headers: { Accept: "application/json" } });
    if (!csrfResponse.ok) throw new Error("Impossible de préparer la déconnexion.");
    const csrf = await csrfResponse.json() as { logoutCsrfToken?: string };
    if (!csrf.logoutCsrfToken) throw new Error("Votre session de sécurité a expiré. Rechargez la page.");
    const response = await fetch(`${API_BASE}/auth/logout`, { method: "POST", credentials: "same-origin", cache: "no-store", headers: { Accept: "application/json", "Content-Type": "application/json" }, body: JSON.stringify({ _csrf_token: csrf.logoutCsrfToken }) });
    if (!response.ok) throw new Error("La déconnexion a échoué. Réessayez.");
  },
  health: (signal?: AbortSignal) => apiResource<HealthResponse>("/health", signal),
  summary: (signal?: AbortSignal) => apiResource<PlatformSummary>("/admin/summary", signal),
  tenants: (signal?: AbortSignal) => apiResource<Tenant[]>("/admin/tenants", signal),
  routers: (signal?: AbortSignal) => apiResource<Router[]>("/admin/routers", signal),
  payments: (signal?: AbortSignal) => apiResource<Payment[]>("/admin/payments", signal),
  ledger: (signal?: AbortSignal) => apiResource<LedgerEntry[]>("/admin/ledger", signal),
  audit: (signal?: AbortSignal) => apiResource<AuditEvent[]>("/admin/audit", signal),
  operations: (signal?: AbortSignal) => apiResource<OperationalStatus[]>("/admin/operations", signal),
  users: (signal?: AbortSignal) => apiResource<AdminUser[]>("/admin/users", signal),
  subscriptionOffers: (signal?: AbortSignal) => apiResource<{ items: SubscriptionOffer[] }>("/admin/subscription-offers", signal),
  subscriptions: (signal?: AbortSignal) => apiResource<{ items: PlatformSubscription[] }>("/admin/subscriptions", signal),
  setUserActive: (id: string, active: boolean) => write<{ id: string; email: string; active: boolean }>(`/admin/users/${encodeURIComponent(id)}/status`, 'PATCH', { active }),
  saveSubscriptionOffer: (id: string | null, payload: Omit<SubscriptionOffer, "id">) => write<{ offer: SubscriptionOffer }>(id ? `/admin/subscription-offers/${encodeURIComponent(id)}` : "/admin/subscription-offers", id ? "PATCH" : "POST", payload),
  setSubscriptionStatus: (id: string, status: string) => write<{ id: string; status: string }>(`/admin/subscriptions/${encodeURIComponent(id)}/status`, "PATCH", { status }),
};
