"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { api, setOwnerTenant, type ApiHealth, type ApiResult, type DashboardSummary, type OwnerOrganization, type PayoutOverview, type Plan, type Router, type RouterDriver, type Site, type Transaction } from "@/lib/api";

const SITE_SLUG_PATTERN = "[a-z0-9]([a-z0-9\\x2d]{0,78}[a-z0-9])?";
import { Badge as UiBadge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Separator } from "@/components/ui/separator";
import { Activity, Banknote, ChevronLeft, ChevronRight, CircleHelp, LayoutDashboard, MapPin, Router as RouterIcon, Wallet, Wifi } from "lucide-react";
import type { LucideIcon } from "lucide-react";

type Section = "dashboard" | "sites" | "plans" | "routers" | "transactions" | "payouts";

const navigation: { id: Section; label: string; icon: LucideIcon }[] = [
  { id: "dashboard", label: "Vue d’ensemble", icon: LayoutDashboard },
  { id: "sites", label: "Mes sites", icon: MapPin },
  { id: "plans", label: "Forfaits", icon: Wifi },
  { id: "routers", label: "Routeurs", icon: RouterIcon },
  { id: "transactions", label: "Transactions", icon: Wallet },
  { id: "payouts", label: "Reversements", icon: Banknote },
];

const titles: Record<Section, { title: string; description: string }> = {
  dashboard: { title: "Vue d’ensemble", description: "Suivez l’activité de votre réseau Wi-Fi." },
  sites: { title: "Mes sites", description: "Gérez les lieux où votre Wi-Fi est proposé." },
  plans: { title: "Forfaits", description: "Configurez les offres proposées à vos clients." },
  routers: { title: "Routeurs", description: "Surveillez vos équipements MikroTik enrôlés." },
  transactions: { title: "Transactions", description: "Consultez les paiements et leur état de traitement." },
  payouts: { title: "Reversements", description: "Suivez le solde opérateur et demandez un versement Mobile Money." },
};

const empty: ApiResult<never> = { state: "unavailable", message: "Chargement des données…" };

function money(amount: number) {
  return new Intl.NumberFormat("fr-FR", { maximumFractionDigits: 0 }).format(amount) + " XOF";
}

function StatePanel({ result, onReload }: { result: ApiResult<unknown>; onReload: () => void }) {
  return (
    <Card className="state-panel" role="status" aria-live="polite">
      <CardHeader>
        <span className="state-icon" aria-hidden="true">{result.state === "connected" ? <Activity size={18} /> : <CircleHelp size={18} />}</span>
        <CardTitle>{result.state === "connected" ? "Aucune donnée" : "Données indisponibles depuis l’API"}</CardTitle>
        <CardDescription>{result.state === "connected" ? "Aucun élément ne correspond à cette section pour le moment." : result.message}</CardDescription>
      </CardHeader>
      <CardContent><Button variant="outline" onClick={onReload} type="button">Recharger</Button></CardContent>
    </Card>
  );
}

function Badge({ value }: { value: string }) {
  const negative = /failed|error|inactive|suspended|échoué|inactif/i.test(value);
  return <UiBadge variant={negative ? "destructive" : "secondary"}>{value}</UiBadge>;
}

export default function Home() {
  const [section, setSection] = useState<Section>("dashboard");
  const [sidebarCollapsed, setSidebarCollapsed] = useState(false);
  const [health, setHealth] = useState<ApiResult<ApiHealth>>(empty);
  const [dashboard, setDashboard] = useState<ApiResult<DashboardSummary>>(empty);
  const [sites, setSites] = useState<ApiResult<Site[]>>(empty);
  const [plans, setPlans] = useState<ApiResult<Plan[]>>(empty);
  const [routers, setRouters] = useState<ApiResult<Router[]>>(empty);
  const [transactions, setTransactions] = useState<ApiResult<Transaction[]>>(empty);
  const [payouts, setPayouts] = useState<ApiResult<PayoutOverview>>(empty);
  const [organizations, setOrganizations] = useState<ApiResult<OwnerOrganization[]>>(empty);
  const [selectedTenantId, setSelectedTenantId] = useState("");
  const selectedTenantRef = useRef("");
  const [loading, setLoading] = useState(false);

  const reload = useCallback(async () => {
    setLoading(true);
    const [h, orgs] = await Promise.all([api.health(), api.organizations()]);
    setHealth(h);
    setOrganizations(orgs);
    if (orgs.state !== "connected") {
      setDashboard(orgs);
      setSites(orgs);
      setPlans(orgs);
      setRouters(orgs);
      setTransactions(orgs);
      setPayouts(orgs);
      setLoading(false);
      return;
    }

    const availableSelection = orgs.data.some((organization) => organization.id === selectedTenantRef.current)
      ? selectedTenantRef.current
      : orgs.data.length === 1 ? orgs.data[0].id : "";
    selectedTenantRef.current = availableSelection;
    setSelectedTenantId(availableSelection);
    setOwnerTenant(availableSelection || null);
    if (!availableSelection) {
      const selectionRequired: ApiResult<never> = { state: "unavailable", message: orgs.data.length === 0
        ? "Aucune organisation active n’est associée à ce compte."
        : "Sélectionnez une organisation pour charger ses données." };
      setDashboard(selectionRequired);
      setSites(selectionRequired);
      setPlans(selectionRequired);
      setRouters(selectionRequired);
      setTransactions(selectionRequired);
      setPayouts(selectionRequired);
      setLoading(false);
      return;
    }

    const [d, s, p, r, t, payoutData] = await Promise.all([
      api.dashboard(), api.sites(), api.plans(), api.routers(), api.transactions(), api.payouts(),
    ]);
    setDashboard(d);
    setSites(s);
    setPlans(p);
    setRouters(r);
    setTransactions(t);
    setPayouts(payoutData);
    setLoading(false);
  }, []);

  useEffect(() => {
    const timer = window.setTimeout(() => { void reload(); }, 0);
    return () => window.clearTimeout(timer);
  }, [reload]);

  const toggleSidebar = () => setSidebarCollapsed((collapsed) => !collapsed);

  const current = titles[section];
  const apiAvailable = health.state === "connected" && health.data.status === "ok";
  const needsWorkspace = organizations.state === "connected" && organizations.data.length === 0;

  return (
    <div className="app-shell">
      <aside className={`sidebar ${sidebarCollapsed ? "is-collapsed" : ""}`}>
        <Button className="sidebar-toggle" variant="outline" size="icon" type="button" onClick={toggleSidebar} aria-label={sidebarCollapsed ? "Déployer la barre latérale" : "Rétracter la barre latérale"} aria-expanded={!sidebarCollapsed} aria-controls="owner-navigation" title={sidebarCollapsed ? "Déployer la barre latérale" : "Rétracter la barre latérale"}>
          {sidebarCollapsed ? <ChevronRight size={16} aria-hidden /> : <ChevronLeft size={16} aria-hidden />}
        </Button>
        <a className="brand" href="#home" onClick={() => setSection("dashboard")}>
          <span className="brand-mark">M</span><span className="brand-name">miksprit</span>
        </a>
        <div className="workspace-label">ESPACE PROPRIÉTAIRE</div>
        <Separator className="workspace-separator" />
        {needsWorkspace ? <p className="workspace-empty">Créez votre espace pour commencer.</p> : <nav id="owner-navigation" aria-label="Navigation principale">
          {navigation.map((item) => (
            <Button key={item.id} type="button" variant="ghost" className={`nav-item ${section === item.id ? "active" : ""}`} aria-label={item.label} title={sidebarCollapsed ? item.label : undefined} aria-current={section === item.id ? "page" : undefined} onClick={() => setSection(item.id)}>
              <item.icon className="nav-icon" size={17} aria-hidden /><span className="nav-label">{item.label}</span>
              <ChevronRight className="nav-arrow" size={15} aria-hidden />
            </Button>
          ))}
        </nav>}
        <div className="sidebar-bottom">
          <div className="profile"><div className="avatar" aria-hidden="true">P</div><div className="profile-copy"><strong>Compte propriétaire</strong><small>Authentification requise</small></div><span className="more">···</span></div>
        </div>
      </aside>

      <main className={`main-area ${sidebarCollapsed ? "is-collapsed" : ""}`} id="home">
        <header className="topbar">
          <div className="breadcrumbs"><span>Espace propriétaire</span><span className="crumb-sep">/</span><strong>{needsWorkspace ? "Créer mon espace" : current.title}</strong></div>
          <div className="top-actions"><span className={`api-indicator ${apiAvailable ? "connected" : "offline"}`} role="status"><i />{apiAvailable ? "API disponible" : "API indisponible"}</span><Button variant="outline" size="icon" type="button" aria-label="Notifications"><Activity size={16} aria-hidden /></Button><div className="avatar avatar-small">P</div></div>
        </header>

        <div className="content">
          {needsWorkspace ? <>
            <section className="page-heading"><div><p className="overline">MIKSPRIT · ESPACE PRO</p><h1>Créer mon espace Miksprit</h1><p>Votre compte est prêt. Donnez un nom à votre activité, puis ajoutez votre premier site Wi-Fi.</p></div><Button onClick={() => void reload()} disabled={loading}>Actualiser</Button></section>
            <CreateWorkspaceView onCreated={reload} />
          </> : <>
          <section className="page-heading"><div><p className="overline">MIKSPRIT · ESPACE PRO</p><h1>{current.title}</h1><p>{current.description}</p></div><div className="heading-actions"><label className="tenant-picker-label" htmlFor="owner-tenant">Organisation</label><select id="owner-tenant" className="tenant-picker" value={selectedTenantId} disabled={organizations.state !== "connected" || organizations.data.length < 2} onChange={(event) => { const id = event.currentTarget.value; selectedTenantRef.current = id; setOwnerTenant(id || null); setSelectedTenantId(id); void reload(); }} aria-label="Organisation active"><option value="">Choisir une organisation</option>{organizations.state === "connected" && organizations.data.map((organization) => <option key={organization.id} value={organization.id}>{organization.name} · {organization.role}</option>)}</select><Button asChild variant="outline"><a href="/portal/">Portail captif</a></Button><Button onClick={() => void reload()} disabled={loading}><span aria-hidden="true">↻</span>{loading ? "Actualisation…" : "Actualiser"}</Button></div></section>

          {section === "dashboard" && <DashboardView result={dashboard} reload={() => void reload()} />}
          {section === "sites" && <SitesView result={sites} reload={() => void reload()} />}
          {section === "plans" && <PlansView result={plans} sites={sites} reload={() => void reload()} />}
          {section === "routers" && <RoutersView result={routers} sites={sites} reload={() => void reload()} />}
          {section === "transactions" && <TransactionsView result={transactions} reload={() => void reload()} />}
          {section === "payouts" && <PayoutsView result={payouts} reload={() => void reload()} hasTenant={Boolean(selectedTenantId)} />}
          </>}

          <footer className="content-footer"><span>© 2026 Miksprit</span><span><i className="footer-dot" /> Les données sont chargées depuis votre API</span></footer>
        </div>
      </main>
    </div>
  );
}

function CreateWorkspaceView({ onCreated }: { onCreated: () => Promise<void> }) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");

  async function create(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = event.currentTarget;
    const data = new FormData(form);
    setBusy(true);
    setError("");
    try {
      await api.createOrganization({ name: String(data.get("name") ?? ""), slug: String(data.get("slug") ?? "") });
      await onCreated();
    } catch (cause) {
      setError(cause instanceof Error ? cause.message : "La création de votre espace a échoué.");
    } finally {
      setBusy(false);
    }
  }

  return <CreatePanel title="Votre espace propriétaire" description="Votre compte en devient propriétaire immédiatement. Aucun lien d’invitation n’est nécessaire." error={error} onSubmit={(event) => void create(event)}>
    <input name="name" aria-label="Nom de l’activité" placeholder="Nom de votre activité" maxLength={160} required />
    <input name="slug" aria-label="Identifiant URL" placeholder="identifiant-de-votre-espace" pattern={SITE_SLUG_PATTERN} maxLength={80} required />
    <Button type="submit" disabled={busy}>{busy ? "Création…" : "Créer mon espace"}</Button>
  </CreatePanel>;
}

function DashboardView({ result, reload }: { result: ApiResult<DashboardSummary>; reload: () => void }) {
  if (result.state !== "connected") return <StatePanel result={result} onReload={reload} />;
  const stats = [
    { label: "Sites actifs", value: result.data.sites.toLocaleString("fr-FR"), icon: "▦" },
    { label: "Routeurs", value: result.data.routers.toLocaleString("fr-FR"), icon: "⌁" },
    { label: "Sessions actives", value: result.data.activeSessions.toLocaleString("fr-FR"), icon: "◉" },
    { label: "Ventes du jour", value: money(result.data.todaySalesXof), icon: "↗" },
  ];
  return <><section className="stat-grid">{stats.map((stat) => <article className="stat-card" key={stat.label}><div className="stat-label">{stat.label}<span>{stat.icon}</span></div><strong>{stat.value}</strong><small>Données API · actualisées {new Date(result.updatedAt).toLocaleTimeString("fr-FR", { hour: "2-digit", minute: "2-digit" })}</small></article>)}</section><section className="panel"><div className="panel-head"><div><h2>Activité du réseau</h2><p>Les indicateurs apparaîtront lorsque les données seront disponibles.</p></div><Badge value="Temps réel API" /></div><StatePanel result={{ state: "connected", data: null, updatedAt: result.updatedAt }} onReload={reload} /></section></>;
}

function SitesView({ result, reload }: { result: ApiResult<Site[]>; reload: () => void }) {
  const [error, setError] = useState("");
  const [editingSite, setEditingSite] = useState<string | null>(null);
  async function create(form: HTMLFormElement) {
    const data = new FormData(form);
    try { await api.createSite({ name: String(data.get("name") ?? ""), slug: String(data.get("slug") ?? "") }); form.reset(); setError(""); reload(); }
    catch (cause) { setError(cause instanceof Error ? cause.message : "Création impossible."); }
  }
  async function changeStatus(site: Site) { try { await api.setSiteStatus(site.id, site.status === "suspended" ? "active" : "suspended"); setError(""); reload(); } catch (cause) { setError(cause instanceof Error ? cause.message : "Mise à jour impossible."); } }
  async function rename(event: React.FormEvent<HTMLFormElement>, id: string) { event.preventDefault(); const form = new FormData(event.currentTarget); try { await api.updateSite(id, { name: String(form.get("name") ?? "") }); setEditingSite(null); setError(""); reload(); } catch (cause) { setError(cause instanceof Error ? cause.message : "Modification impossible."); } }
  return <><CreatePanel title="Ajouter un site" description="Créez un emplacement pour vos offres Wi-Fi." error={error} onSubmit={(event) => { event.preventDefault(); void create(event.currentTarget); }}><input name="name" aria-label="Nom du site" placeholder="Nom du site" minLength={1} maxLength={120} required/><input name="slug" aria-label="Identifiant du site" placeholder="identifiant-du-site" pattern={SITE_SLUG_PATTERN} required/><Button type="submit">Créer le site</Button></CreatePanel>{result.state === "connected" && result.data.length > 0 ? <TablePanel title="Sites" columns={["Nom", "Emplacement", "État", "Actions"]}>{result.data.map((site) => <tr key={site.id}><td><strong>{site.name}</strong></td><td>{site.location || "—"}</td><td><Badge value={site.status} /></td><td className="owner-row-actions">{editingSite === site.id ? <form onSubmit={(event) => void rename(event, site.id)}><input name="name" aria-label="Nouveau nom du site" defaultValue={site.name} maxLength={120} required/><Button size="sm" type="submit">Enregistrer</Button><Button size="sm" type="button" variant="outline" onClick={() => setEditingSite(null)}>Annuler</Button></form> : <><Button size="sm" variant="outline" onClick={() => setEditingSite(site.id)}>Modifier</Button><Button size="sm" variant="outline" onClick={() => void changeStatus(site)}>{site.status === "suspended" ? "Réactiver" : site.status === "draft" ? "Activer" : "Suspendre"}</Button></>}</td></tr>)}</TablePanel> : <StatePanel result={result} onReload={reload} />}</>;
}

function PlansView({ result, sites, reload }: { result: ApiResult<Plan[]>; sites: ApiResult<Site[]>; reload: () => void }) {
  const [error, setError] = useState("");
  async function create(form: HTMLFormElement) {
    const data = new FormData(form);
    try { await api.createPlan({ name: String(data.get("name") ?? ""), siteId: String(data.get("siteId") ?? ""), priceXof: Number(data.get("priceXof")), sessionSeconds: Number(data.get("sessionSeconds")), dataLimitBytes: data.get("dataLimitBytes") ? Number(data.get("dataLimitBytes")) : null, deviceLimit: Number(data.get("deviceLimit")) }); form.reset(); setError(""); reload(); }
    catch (cause) { setError(cause instanceof Error ? cause.message : "Création impossible."); }
  }
  async function changeStatus(plan: Plan) { try { await api.setPlanStatus(plan.id, plan.status === "published" ? "draft" : "published"); setError(""); reload(); } catch (cause) { setError(cause instanceof Error ? cause.message : "Mise à jour impossible."); } }
  async function update(event: React.FormEvent<HTMLFormElement>, plan: Plan) { event.preventDefault(); const data = new FormData(event.currentTarget); try { await api.updatePlan(plan.id, { name: String(data.get("name") ?? ""), priceXof: Number(data.get("priceXof")), sessionSeconds: Number(data.get("sessionSeconds")), dataLimitBytes: data.get("dataLimitBytes") ? Number(data.get("dataLimitBytes")) : null, deviceLimit: Number(data.get("deviceLimit")) }); setError(""); reload(); } catch (cause) { setError(cause instanceof Error ? cause.message : "Modification impossible."); } }
  return <><CreatePanel title="Créer un forfait" description="Le prix et les limites seront figés sur chaque commande." error={error} onSubmit={(event) => { event.preventDefault(); void create(event.currentTarget); }}><input name="name" aria-label="Nom du forfait" placeholder="Nom du forfait" maxLength={120} required/><select name="siteId" aria-label="Site" required defaultValue="">{sites.state === "connected" && sites.data.map((site) => <option key={site.id} value={site.id}>{site.name}</option>)}</select><input name="priceXof" aria-label="Prix en XOF" placeholder="Prix (XOF)" type="number" min="1" step="1" required/><input name="sessionSeconds" aria-label="Durée en secondes" placeholder="Durée (secondes)" type="number" min="1" required/><input name="dataLimitBytes" aria-label="Limite de données en octets" placeholder="Limite données (octets, facultative)" type="number" min="1"/><input name="deviceLimit" aria-label="Nombre d’appareils" placeholder="Appareils" type="number" min="1" defaultValue="1" required/><Button type="submit" disabled={sites.state !== "connected" || sites.data.length === 0}>Créer le forfait</Button></CreatePanel>{result.state === "connected" && result.data.length > 0 ? <TablePanel title="Forfaits" columns={["Nom", "Prix", "Durée", "État", "Actions"]}>{result.data.map((plan) => <tr key={plan.id}><td><strong>{plan.name}</strong></td><td>{money(plan.priceXof)}</td><td>{plan.durationLabel}</td><td><Badge value={plan.status} /></td><td className="owner-row-actions">{plan.status !== "archived" && <><details className="owner-edit"><summary>Modifier</summary><form onSubmit={(event) => void update(event, plan)}><input name="name" aria-label="Nom du forfait" defaultValue={plan.name} maxLength={120} required/><input name="priceXof" aria-label="Prix en XOF" type="number" min="1" defaultValue={plan.priceXof} required/><input name="sessionSeconds" aria-label="Durée en secondes" type="number" min="1" defaultValue={plan.sessionSeconds} required/><input name="dataLimitBytes" aria-label="Limite de données en octets" type="number" min="1" defaultValue={plan.dataLimitBytes ?? ""}/><input name="deviceLimit" aria-label="Nombre d’appareils" type="number" min="1" defaultValue={plan.deviceLimit} required/><Button size="sm" type="submit">Enregistrer</Button></form></details><Button size="sm" variant="outline" onClick={() => void changeStatus(plan)}>{plan.status === "published" ? "Dépublier" : "Publier"}</Button></>}</td></tr>)}</TablePanel> : <StatePanel result={result} onReload={reload} />}</>;
}

function RoutersView({ result, sites, reload }: { result: ApiResult<Router[]>; sites: ApiResult<Site[]>; reload: () => void }) {
  const [error, setError] = useState("");
  const [drivers, setDrivers] = useState<ApiResult<RouterDriver[]>>(empty);
  useEffect(() => { let active = true; void api.routerDrivers().then((value) => { if (active) setDrivers(value); }); return () => { active = false; }; }, []);
  async function create(form: HTMLFormElement) {
    const data = new FormData(form);
    try { await api.createRouter({ name: String(data.get("name") ?? ""), siteId: String(data.get("siteId") ?? ""), host: String(data.get("host") ?? ""), apiPort: Number(data.get("apiPort")), credentialReference: String(data.get("credentialReference") ?? ""), providerCode: String(data.get("providerCode") ?? "") }); form.reset(); setError(""); reload(); }
    catch (cause) { setError(cause instanceof Error ? cause.message : "Enrôlement impossible."); }
  }
  async function suspend(router: Router) { try { await api.suspendRouter(router.id); setError(""); reload(); } catch (cause) { setError(cause instanceof Error ? cause.message : "Mise à jour impossible."); } }
  async function verify(router: Router) { try { await api.verifyRouter(router.id); setError(""); reload(); } catch (cause) { setError(cause instanceof Error ? cause.message : "Le contrôle du routeur a échoué."); } }
  return <><CreatePanel title="Enregistrer un routeur" description="Le routeur reste en attente jusqu’à la vérification de sa connexion TLS." error={error} onSubmit={(event) => { event.preventDefault(); void create(event.currentTarget); }}><input name="name" aria-label="Nom du routeur" placeholder="Nom du routeur" maxLength={120} required/><select name="siteId" aria-label="Site" required defaultValue="">{sites.state === "connected" && sites.data.map((site) => <option key={site.id} value={site.id}>{site.name}</option>)}</select><select name="providerCode" aria-label="Kit Wi-Fi" required defaultValue="mikrotik" disabled={drivers.state !== "connected"}>{drivers.state === "connected" && drivers.data.map((driver) => <option key={driver.code} value={driver.code}>{driver.code}</option>)}</select><input name="host" aria-label="Hôte ou adresse IP" placeholder="Hôte ou adresse IP" maxLength={253} required/><input name="apiPort" aria-label="Port API TLS" placeholder="Port API TLS" type="number" min="1" max="65535" defaultValue="8729" required/><input name="credentialReference" aria-label="Référence du secret" placeholder="Référence du secret" maxLength={160} required/><Button type="submit" disabled={sites.state !== "connected" || sites.data.length === 0 || drivers.state !== "connected" || drivers.data.length === 0}>Enregistrer le routeur</Button></CreatePanel>{result.state === "connected" && result.data.length > 0 ? <TablePanel title="Routeurs enregistrés" columns={["Nom", "Site", "Kit", "Version", "État", "Actions"]}>{result.data.map((router) => <tr key={router.id}><td><strong>{router.name}</strong></td><td>{router.siteName}</td><td>{router.providerCode}</td><td>{router.version || "—"}</td><td><Badge value={router.status} /></td><td className="owner-row-actions">{router.status === "active" ? <Button size="sm" variant="outline" onClick={() => void suspend(router)}>Suspendre</Button> : <Button size="sm" variant="outline" onClick={() => void verify(router)}>Vérifier et activer</Button>}</td></tr>)}</TablePanel> : <StatePanel result={result} onReload={reload} />}</>;
}

function CreatePanel({ title, description, error, onSubmit, children }: { title: string; description: string; error: string; onSubmit: (event: React.FormEvent<HTMLFormElement>) => void; children: React.ReactNode }) {
  return <section className="panel create-panel"><div className="panel-head"><div><h2>{title}</h2><p>{description}</p></div></div><form className="owner-create-form" onSubmit={onSubmit}>{children}</form>{error && <p role="alert" className="form-error">{error}</p>}</section>;
}

function TransactionsView({ result, reload }: { result: ApiResult<Transaction[]>; reload: () => void }) {
  if (result.state !== "connected" || result.data.length === 0) return <StatePanel result={result} onReload={reload} />;
  const settlementLabels: Record<string, string> = { awaiting_payment: "Paiement en attente", awaiting_settlement: "Règlement en attente", available: "Disponible", needs_review: "À rapprocher" };
  return <TablePanel title="Transactions" columns={["Référence", "Payé", "Frais FedaPay", "Commission Miksprit", "Dû opérateur", "Règlement", "État"]}>{result.data.map((transaction) => <tr key={transaction.id}><td><strong>{transaction.reference}</strong></td><td>{money(transaction.amountXof)}</td><td>{transaction.providerFeeXof === null ? "En attente" : money(transaction.providerFeeXof)}</td><td>{transaction.commissionXof === null ? "—" : money(transaction.commissionXof)}</td><td>{transaction.operatorNetXof === null ? "En attente" : money(transaction.operatorNetXof)}</td><td><Badge value={settlementLabels[transaction.settlementStatus] ?? transaction.settlementStatus} /></td><td><Badge value={transaction.status} /></td></tr>)}</TablePanel>;
}

function TablePanel({ title, columns, children }: { title: string; columns: string[]; children: React.ReactNode }) {
  return <section className="panel table-panel"><div className="panel-head"><div><h2>{title}</h2><p>Informations renvoyées par l’API du tenant actif.</p></div></div><div className="table-scroll"><table><thead><tr>{columns.map((column) => <th key={column}>{column}</th>)}</tr></thead><tbody>{children}</tbody></table></div></section>;
}

const E164_PATTERN = "\\+229[0-9]{8}";

function payoutStatusLabel(status: string): string {
  return ({
    requested: "Demandé",
    reserved: "Réservé",
    processing: "En traitement",
    pending: "En attente",
    sent: "Versé",
    failed: "Échec",
    ambiguous: "À vérifier",
  } as Record<string, string>)[status] ?? status;
}

function PayoutsView({ result, reload, hasTenant }: { result: ApiResult<PayoutOverview>; reload: () => void; hasTenant: boolean }) {
  const [amount, setAmount] = useState("");
  const [phoneNumber, setPhoneNumber] = useState("");
  const [beneficiaryName, setBeneficiaryName] = useState("");
  const [review, setReview] = useState(false);
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState("");
  const [error, setError] = useState("");
  const idempotency = useRef<{ fingerprint: string; key: string } | null>(null);

  if (result.state !== "connected") {
    return <StatePanel result={result} onReload={reload} />;
  }

  const available = result.data.balance.availableXof;
  const amountXof = Number(amount);
  const fingerprint = JSON.stringify([amountXof, phoneNumber, beneficiaryName.trim()]);

  function prepare(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setError("");
    setMessage("");
    if (!hasTenant) {
      setError("Sélectionnez une organisation active pour continuer.");
      return;
    }
    if (!Number.isSafeInteger(amountXof) || amountXof < 1 || amountXof > available) {
      setError("Le montant doit être un nombre entier inférieur ou égal au solde disponible.");
      return;
    }
    if (!/^\+229\d{8}$/.test(phoneNumber)) {
      setError("Pour le moment, saisissez un numéro Mobile Money béninois au format +229XXXXXXXX.");
      return;
    }
    if (beneficiaryName.trim().length < 2 || beneficiaryName.trim().length > 160) {
      setError("Saisissez le nom du titulaire exact du compte Mobile Money.");
      return;
    }
    setReview(true);
  }

  async function confirmPayout() {
    if (busy) return;
    setBusy(true);
    setError("");
    setMessage("");
    if (idempotency.current?.fingerprint !== fingerprint) {
      idempotency.current = { fingerprint, key: window.crypto.randomUUID() };
    }
    try {
      await api.requestPayout({
        amountXof,
        phoneNumber,
        beneficiaryName: beneficiaryName.trim(),
      }, idempotency.current.key);
      idempotency.current = null;
      setReview(false);
      setAmount("");
      setPhoneNumber("");
      setBeneficiaryName("");
      setMessage("La demande est enregistrée et le montant est réservé. Son statut sera actualisé après confirmation du transfert.");
      reload();
    } catch (cause) {
      setError(cause instanceof Error ? cause.message : "La demande de reversement n’a pas pu être enregistrée.");
    } finally {
      setBusy(false);
    }
  }

  return (
    <>
      <section className="stat-grid" aria-label="Solde opérateur">
        {[
          { label: "Disponible", value: result.data.balance.availableXof },
          { label: "Réservé", value: result.data.balance.reservedXof },
          { label: "Déjà versé", value: result.data.balance.paidXof },
        ].map((item) => (
          <article className="stat-card" key={item.label}>
            <div className="stat-label">{item.label}<span aria-hidden="true"><Banknote size={15} /></span></div>
            <strong>{money(item.value)}</strong>
          </article>
        ))}
      </section>

      <section className="panel create-panel">
        <div className="panel-head">
          <div>
            <h2>Demander un reversement</h2>
            <p>Le montant demandé est réservé jusqu’à la confirmation du transfert Mobile Money.</p>
          </div>
        </div>
        {!result.data.payoutsEnabled ? (
          <p role="status">Les reversements Mobile Money ne sont pas encore activés sur le compte FedaPay de Miksprit.</p>
        ) : !hasTenant ? (
          <p role="status">Aucune organisation active n’est sélectionnée. La demande est indisponible.</p>
        ) : review ? (
          <div aria-live="polite">
            <dl className="payout-review">
              <div><dt>Montant demandé</dt><dd>{money(amountXof)}</dd></div>
              <div><dt>Canal</dt><dd>Mobile Money</dd></div>
              <div><dt>Numéro destinataire</dt><dd>{phoneNumber}</dd></div>
              <div><dt>Titulaire déclaré</dt><dd>{beneficiaryName.trim()}</dd></div>
            </dl>
            <p className="payout-notice">Vérifiez le numéro et le nom du titulaire. Une fois le transfert soumis au réseau, son résultat peut nécessiter un délai de confirmation.</p>
            {error && <p role="alert" className="form-error">{error}</p>}
            <div className="owner-row-actions">
              <Button type="button" variant="outline" onClick={() => { setReview(false); setError(""); }} disabled={busy}>Modifier</Button>
              <Button type="button" onClick={() => void confirmPayout()} disabled={busy}>
                {busy ? "Enregistrement…" : "Confirmer la demande"}
              </Button>
            </div>
          </div>
        ) : (
          <form className="payout-form" onSubmit={prepare}>
            <label className="payout-field" htmlFor="payout-amount">Montant à retirer (XOF)
              <input id="payout-amount" name="amountXof" type="number" inputMode="numeric" min="1" max={available} step="1" required value={amount} onChange={(event) => { setAmount(event.currentTarget.value); setError(""); }} />
            </label>
            <label className="payout-field" htmlFor="payout-phone">Numéro du portefeuille
              <input id="payout-phone" name="phoneNumber" type="tel" inputMode="tel" autoComplete="tel" placeholder="+229XXXXXXXX" pattern={E164_PATTERN} maxLength={16} required value={phoneNumber} onChange={(event) => { setPhoneNumber(event.currentTarget.value); setError(""); }} />
            </label>
            <label className="payout-field" htmlFor="payout-beneficiary">Nom exact du titulaire
              <input id="payout-beneficiary" name="beneficiaryName" autoComplete="name" maxLength={160} required value={beneficiaryName} onChange={(event) => { setBeneficiaryName(event.currentTarget.value); setError(""); }} />
            </label>
            {error && <p role="alert" className="form-error payout-form-message">{error}</p>}
            {message && <p role="status" className="payout-notice payout-form-message">{message}</p>}
            <Button type="submit" disabled={!result.data.payoutsEnabled || !hasTenant || available < 1 || busy}>
              {busy ? "Traitement…" : "Vérifier la demande"}
            </Button>
          </form>
        )}
      </section>

      <section className="panel table-panel">
        <div className="panel-head"><div><h2>Historique des demandes</h2><p>Les reversements ne sont considérés comme payés qu’après confirmation.</p></div></div>
        {result.data.items.length === 0 ? (
          <p role="status">Aucune demande de reversement pour cette organisation.</p>
        ) : (
          <div className="table-scroll"><table><thead><tr><th>Montant</th><th>Canal</th><th>Numéro</th><th>Date</th><th>État</th></tr></thead>
            <tbody>{result.data.items.map((item) => (
              <tr key={item.id}>
                <td><strong>{money(item.amountXof)}</strong></td>
                <td>Mobile Money</td>
                <td>•••• {item.phoneLast4}</td>
                <td>{new Date(item.createdAt).toLocaleString("fr-FR")}</td>
                <td><Badge value={payoutStatusLabel(item.status)} /></td>
              </tr>
            ))}</tbody>
          </table></div>
        )}
      </section>
    </>
  );
}
