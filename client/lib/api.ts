export type ApiResult<T> =
  | { state: "connected"; data: T; updatedAt: string }
  | { state: "unavailable"; message: string };

export type DashboardSummary = {
  sites: number;
  routers: number;
  activeSessions: number;
  todaySalesXof: number;
};

export type Site = { id: string; name: string; location?: string; status: string };
export type Plan = { id: string; siteId: string; name: string; priceXof: number; sessionSeconds: number; dataLimitBytes: number | null; deviceLimit: number; durationLabel: string; status: string };
export type Router = { id: string; name: string; siteName: string; providerCode: string; status: string; version?: string };
export type RouterDriver = { code: string };
export type Transaction = { id: string; reference: string; amountXof: number; providerFeeXof: number | null; commissionXof: number | null; operatorNetXof: number | null; settlementStatus: string; status: string; createdAt: string };
export type PayoutBalance = { availableXof: number; reservedXof: number; paidXof: number };
export type PayoutRequest = { id: string; amountXof: number; provider: "mobile_money"; phoneLast4: string; status: string; createdAt: string };
export type PayoutOverview = { payoutsEnabled: boolean; balance: PayoutBalance; items: PayoutRequest[] };
export type ApiHealth = { status: string; service: string };
export type OwnerOrganization = { id: string; name: string; role: string };
export type OwnerMember = { id: string; email: string; role: string; status: string; createdAt: string; acceptedAt: string | null };
export type OwnerHotspotSession = { id: string; routerName: string; siteName: string; voucherId: string; status: string; startedAt: string | null; endedAt: string | null; bytesIn: string | null; bytesOut: string | null };
export type SubscriptionOffer = { id: string; code: string; name: string; provider: string; amountXof: number; intervalDays: number; graceDays: number; expiredAccessPolicy: string; active: boolean };
export type PlatformSubscription = { id: string; status: string; offerId: string; offerName: string; amountXof: number; intervalDays: number; startedAt: string | null; currentPeriodStart: string | null; currentPeriodEnd: string | null; nextDueAt: string | null; updatedAt: string; expiredAccessPolicy: string };
export type SubscriptionPayment = { id: string; provider: string; reference: string; providerReference: string | null; amountXof: number; currency: string; status: string; createdAt: string; paidAt: string | null };
export type OwnerSubscriptionSummary = { subscription: { billingEnabled: boolean; offers: SubscriptionOffer[]; subscription: PlatformSubscription | null; payments: SubscriptionPayment[] } };
export type PaymentSettings = { availableProviders: { code: string }[]; providerEnabled: boolean; configuration: { provider: string | null; environment: string | null; configured: boolean; lastApiCheckAt: string | null; updatedAt: string | null } };

const API_BASE = (process.env.NEXT_PUBLIC_API_BASE ?? "/api/v1").replace(/\/$/, "");
const LOGIN_URL = process.env.NEXT_PUBLIC_LANDING_LOGIN_URL ?? (process.env.NODE_ENV === "development" ? "http://localhost:3002/connexion" : "/connexion");
let selectedTenantId: string | null = null;

export function setOwnerTenant(tenantId: string | null): void {
  selectedTenantId = tenantId;
}

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

  const login = new URL(LOGIN_URL, window.location.origin);
  login.searchParams.set("returnTo", `${window.location.origin}${window.location.pathname}`);
  window.location.assign(login.toString());
}

async function get<T>(path: string, signal?: AbortSignal): Promise<T> {
  scheduleSessionRefresh();
  const headers: Record<string, string> = { Accept: "application/json" };
  if (path.startsWith("/owner/") && selectedTenantId) headers["X-Tenant-ID"] = selectedTenantId;
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

async function write<T>(path: string, method: "POST" | "PATCH" | "PUT" | "DELETE", payload: Record<string, unknown>, idempotencyKey?: string): Promise<T> {
  scheduleSessionRefresh();
  if (path.startsWith("/owner/") && path !== "/owner/organizations" && !selectedTenantId) {
    throw new Error("Créez votre espace Miksprit avant d’ajouter un site ou un forfait.");
  }
  const csrfResponse = await fetch(`${API_BASE}/auth/csrf`, { credentials: "same-origin", cache: "no-store", headers: { Accept: "application/json" } });
  if (!csrfResponse.ok) throw new Error("Impossible de vérifier votre accès.");
  const csrf = await csrfResponse.json() as { csrfToken?: string };
  if (!csrf.csrfToken) throw new Error("Jeton de sécurité absent. Rechargez la page.");
  const headers: Record<string, string> = { Accept: "application/json", "Content-Type": "application/json" };
  if (selectedTenantId) headers["X-Tenant-ID"] = selectedTenantId;
  if (idempotencyKey) headers["Idempotency-Key"] = idempotencyKey;
  const response = await fetch(`${API_BASE}${path}`, { method, headers, credentials: "same-origin", cache: "no-store", body: JSON.stringify({ ...payload, _csrf_token: csrf.csrfToken }) });
  if (response.status === 401) {
    if (await refreshSession()) throw new Error("Session rétablie. Renvoyez le formulaire.");
    redirectToCentralLogin();
    throw new Error("Votre connexion a expiré.");
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
    if (!csrf.logoutCsrfToken) throw new Error("Jeton de sécurité absent. Rechargez la page.");
    const response = await fetch(`${API_BASE}/auth/logout`, { method: "POST", credentials: "same-origin", cache: "no-store", headers: { Accept: "application/json", "Content-Type": "application/json" }, body: JSON.stringify({ _csrf_token: csrf.logoutCsrfToken }) });
    if (!response.ok) throw new Error("La déconnexion n’a pas pu être confirmée par le serveur.");
  },
  health: (signal?: AbortSignal) => apiResource<ApiHealth>("/health", signal),
  organizations: (signal?: AbortSignal) => apiResource<OwnerOrganization[]>("/owner/organizations", signal),
  createOrganization: (payload: { name: string; slug: string }) => write<{ id: string; name: string; slug: string; role: string }>("/owner/organizations", "POST", payload),
  dashboard: (signal?: AbortSignal) => apiResource<DashboardSummary>("/owner/dashboard", signal),
  sites: (signal?: AbortSignal) => apiResource<Site[]>("/owner/sites", signal),
  plans: (signal?: AbortSignal) => apiResource<Plan[]>("/owner/plans", signal),
  routers: (signal?: AbortSignal) => apiResource<Router[]>("/owner/routers", signal),
  routerDrivers: (signal?: AbortSignal) => apiResource<RouterDriver[]>("/owner/router-drivers", signal),
  transactions: (signal?: AbortSignal) => apiResource<Transaction[]>("/owner/transactions", signal),
  sessions: (signal?: AbortSignal) => apiResource<{ items: OwnerHotspotSession[] }>("/owner/sessions", signal),
  payouts: (signal?: AbortSignal) => apiResource<PayoutOverview>("/owner/payouts", signal),
  paymentSettings: (signal?: AbortSignal) => apiResource<PaymentSettings>("/owner/payment-settings", signal),
  members: (signal?: AbortSignal) => apiResource<{ items: OwnerMember[] }>("/owner/members", signal),
  subscription: (signal?: AbortSignal) => apiResource<OwnerSubscriptionSummary>("/owner/subscription", signal),
  checkoutSubscription: (offerId: string) => write<{ checkoutUrl: string | null; paymentId: string; state: string }>("/owner/subscription/checkout", "POST", { offerId }),
  inviteMember: (payload: { email: string; role: string }) => write<OwnerMember>("/owner/members", "POST", payload),
  setMemberRole: (id: string, role: string) => write(`/owner/members/${encodeURIComponent(id)}/role`, "PATCH", { role }),
  setMemberStatus: (id: string, status: string) => write(`/owner/members/${encodeURIComponent(id)}/status`, "PATCH", { status }),
  savePaymentSettings: (payload: { provider: string; environment: "test" | "live"; apiKey: string; webhookSecret: string }) => write<{ configuration: PaymentSettings["configuration"] }>("/owner/payment-settings", "PUT", payload),
  testPaymentSettings: (payload: { provider: string; environment: "test" | "live"; apiKey: string; webhookSecret: string }) => write<{ valid: boolean }>("/owner/payment-settings/test", "POST", payload),
  revokePaymentSettings: () => write<{ configuration: PaymentSettings["configuration"] }>("/owner/payment-settings", "DELETE", {}),
  requestPayout: (payload: { amountXof: number; phoneNumber: string; beneficiaryName: string }, idempotencyKey: string) =>
    write<{ id: string; amountXof: number; status: string }>("/owner/payouts", "POST", payload, idempotencyKey),
  createSite: (payload: { name: string }) => write<{ id: string; slug: string }>("/owner/sites", "POST", payload),
  createPlan: (payload: { name: string; siteId: string; priceXof: number; sessionSeconds: number; dataLimitBytes: number | null; deviceLimit: number }) => write<{ id: string }>("/owner/plans", "POST", payload),
  createRouter: (payload: { name: string; siteId: string; host: string; apiPort: number; username: string; password: string; providerCode: string }) => write<{ id: string }>("/owner/routers", "POST", payload),
  replaceRouterCredentials: (id: string, payload: { username: string; password: string }) => write(`/owner/routers/${encodeURIComponent(id)}/credentials`, "PUT", payload),
  revokeRouterCredentials: (id: string) => write(`/owner/routers/${encodeURIComponent(id)}/credentials`, "DELETE", {}),
  updateSite: (id: string, payload: { name: string }) => write(`/owner/sites/${encodeURIComponent(id)}`, "PATCH", payload),
  setSiteStatus: (id: string, status: string) => write(`/owner/sites/${encodeURIComponent(id)}/status`, "PATCH", { status }),
  updatePlan: (id: string, payload: { name: string; priceXof: number; sessionSeconds: number; dataLimitBytes: number | null; deviceLimit: number }) => write(`/owner/plans/${encodeURIComponent(id)}`, "PATCH", payload),
  setPlanStatus: (id: string, status: string) => write(`/owner/plans/${encodeURIComponent(id)}/status`, "PATCH", { status }),
  suspendRouter: (id: string) => write(`/owner/routers/${encodeURIComponent(id)}/status`, "PATCH", { status: "suspended" }),
  verifyRouter: (id: string) => write<{ id: string; status: string; version: string }>(`/owner/routers/${encodeURIComponent(id)}/verify`, "POST", {}),
};
