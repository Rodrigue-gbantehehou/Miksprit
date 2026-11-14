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

const API_BASE = (process.env.NEXT_PUBLIC_API_BASE ?? "/api/v1").replace(/\/$/, "");
const LOGIN_URL = process.env.NEXT_PUBLIC_LANDING_LOGIN_URL ?? "http://localhost:3002/connexion";
let selectedTenantId: string | null = null;

export function setOwnerTenant(tenantId: string | null): void {
  selectedTenantId = tenantId;
}

function redirectToCentralLogin(): void {
  if (typeof window === "undefined") return;

  const login = new URL(LOGIN_URL, window.location.origin);
  login.searchParams.set("returnTo", `${window.location.origin}${window.location.pathname}`);
  window.location.assign(login.toString());
}

async function get<T>(path: string, signal?: AbortSignal): Promise<T> {
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

async function write<T>(path: string, method: "POST" | "PATCH", payload: Record<string, unknown>, idempotencyKey?: string): Promise<T> {
  if (path.startsWith("/owner/") && !selectedTenantId) {
    throw new Error("Ce compte n’est lié à aucune organisation active. Un administrateur de la plateforme doit d’abord vous inviter comme propriétaire.");
  }
  const csrfResponse = await fetch(`${API_BASE}/auth/csrf`, { credentials: "same-origin", cache: "no-store", headers: { Accept: "application/json" } });
  if (!csrfResponse.ok) throw new Error("Impossible de vérifier votre accès.");
  const csrf = await csrfResponse.json() as { csrfToken?: string };
  if (!csrf.csrfToken) throw new Error("Jeton de sécurité absent. Rechargez la page.");
  const headers: Record<string, string> = { Accept: "application/json", "Content-Type": "application/json" };
  if (selectedTenantId) headers["X-Tenant-ID"] = selectedTenantId;
  if (idempotencyKey) headers["Idempotency-Key"] = idempotencyKey;
  const response = await fetch(`${API_BASE}${path}`, { method, headers, credentials: "same-origin", cache: "no-store", body: JSON.stringify({ ...payload, _csrf_token: csrf.csrfToken }) });
  if (response.status === 401) { redirectToCentralLogin(); throw new Error("Votre connexion a expiré."); }
  const result = await response.json().catch(() => ({})) as T & { error?: string };
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
  health: (signal?: AbortSignal) => apiResource<ApiHealth>("/health", signal),
  organizations: (signal?: AbortSignal) => apiResource<OwnerOrganization[]>("/owner/organizations", signal),
  dashboard: (signal?: AbortSignal) => apiResource<DashboardSummary>("/owner/dashboard", signal),
  sites: (signal?: AbortSignal) => apiResource<Site[]>("/owner/sites", signal),
  plans: (signal?: AbortSignal) => apiResource<Plan[]>("/owner/plans", signal),
  routers: (signal?: AbortSignal) => apiResource<Router[]>("/owner/routers", signal),
  routerDrivers: (signal?: AbortSignal) => apiResource<RouterDriver[]>("/owner/router-drivers", signal),
  transactions: (signal?: AbortSignal) => apiResource<Transaction[]>("/owner/transactions", signal),
  payouts: (signal?: AbortSignal) => apiResource<PayoutOverview>("/owner/payouts", signal),
  requestPayout: (payload: { amountXof: number; phoneNumber: string; beneficiaryName: string }, idempotencyKey: string) =>
    write<{ id: string; amountXof: number; status: string }>("/owner/payouts", "POST", payload, idempotencyKey),
  createSite: (payload: { name: string; slug: string }) => write<{ id: string }>("/owner/sites", "POST", payload),
  createPlan: (payload: { name: string; siteId: string; priceXof: number; sessionSeconds: number; dataLimitBytes: number | null; deviceLimit: number }) => write<{ id: string }>("/owner/plans", "POST", payload),
  createRouter: (payload: { name: string; siteId: string; host: string; apiPort: number; credentialReference: string; providerCode: string }) => write<{ id: string }>("/owner/routers", "POST", payload),
  updateSite: (id: string, payload: { name: string }) => write(`/owner/sites/${encodeURIComponent(id)}`, "PATCH", payload),
  setSiteStatus: (id: string, status: string) => write(`/owner/sites/${encodeURIComponent(id)}/status`, "PATCH", { status }),
  updatePlan: (id: string, payload: { name: string; priceXof: number; sessionSeconds: number; dataLimitBytes: number | null; deviceLimit: number }) => write(`/owner/plans/${encodeURIComponent(id)}`, "PATCH", payload),
  setPlanStatus: (id: string, status: string) => write(`/owner/plans/${encodeURIComponent(id)}/status`, "PATCH", { status }),
  suspendRouter: (id: string) => write(`/owner/routers/${encodeURIComponent(id)}/status`, "PATCH", { status: "suspended" }),
  verifyRouter: (id: string) => write<{ id: string; status: string; version: string }>(`/owner/routers/${encodeURIComponent(id)}/verify`, "POST", {}),
};
