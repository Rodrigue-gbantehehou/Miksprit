"use client";

import { useCallback, useEffect, useId, useRef, useState } from "react";
import { api, CENTRAL_LOGIN_URL, setOwnerTenant, type ApiResult, type DashboardSummary, type OwnerHotspotSession, type OwnerMember, type OwnerOrganization, type OwnerSubscriptionSummary, type PaymentSettings, type Plan, type Router, type RouterDriver, type Site, type Transaction } from "@/lib/api";

const SITE_SLUG_PATTERN = "[a-z0-9]([a-z0-9\\x2d]{0,78}[a-z0-9])?";
import { Badge as UiBadge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Separator } from "@/components/ui/separator";
import { Activity, ChevronLeft, ChevronRight, CircleHelp, CreditCard, LayoutDashboard, MapPin, Menu, Router as RouterIcon, Users, Wallet, Wifi, X } from "lucide-react";
import type { LucideIcon } from "lucide-react";

type Section = "dashboard" | "sites" | "plans" | "routers" | "sessions" | "transactions" | "payment-settings" | "members" | "subscription";

const navigation: { id: Section; label: string; icon: LucideIcon }[] = [
  { id: "dashboard", label: "Vue d’ensemble", icon: LayoutDashboard },
  { id: "sites", label: "Mes sites", icon: MapPin },
  { id: "plans", label: "Forfaits", icon: Wifi },
  { id: "routers", label: "Routeurs", icon: RouterIcon },
  { id: "sessions", label: "Sessions Wi-Fi", icon: Activity },
  { id: "transactions", label: "Transactions", icon: Wallet },
  { id: "payment-settings", label: "Paiement des tickets", icon: CreditCard },
  { id: "members", label: "Membres et rôles", icon: Users },
  { id: "subscription", label: "Abonnement Miksprit", icon: CreditCard },
];

const titles: Record<Section, { title: string; description: string }> = {
  dashboard: { title: "Vue d’ensemble", description: "Activité de votre réseau Wi-Fi." },
  sites: { title: "Mes sites", description: "Gérez vos points Wi-Fi." },
  plans: { title: "Forfaits", description: "Définissez vos offres Wi-Fi." },
  routers: { title: "Routeurs", description: "Gérez vos routeurs MikroTik." },
  sessions: { title: "Sessions Wi-Fi", description: "Sessions sur vos routeurs actifs." },
  transactions: { title: "Transactions", description: "Paiements et statuts." },
  "payment-settings": { title: "Paiement des tickets", description: "Configurez le compte qui reçoit vos paiements." },
  members: { title: "Membres et rôles", description: "Gérez les accès de votre équipe." },
  subscription: { title: "Abonnement Miksprit", description: "Gérez votre abonnement." },
};

const empty: ApiResult<never> = { state: "unavailable", message: "Chargement des données…" };

function money(amount: number) {
  return new Intl.NumberFormat("fr-FR", { maximumFractionDigits: 0 }).format(amount) + " XOF";
}

function organizationSlugFromName(name: string): string {
  if (name.trim() === "") return "";
  const slug = name.normalize("NFD").replace(/[\u0300-\u036f]/g, "").toLowerCase()
    .replace(/[^a-z0-9]+/g, "-").replace(/^-+|-+$/g, "").slice(0, 80).replace(/-+$/g, "");
  return slug || "mon-espace";
}

function orgsRole(result: ApiResult<OwnerOrganization[]>, tenantId: string): string | null {
  return result.state === "connected" ? result.data.find((organization) => organization.id === tenantId)?.role ?? null : null;
}

function StatePanel({ result, onReload }: { result: ApiResult<unknown>; onReload: () => void }) {
  return (
    <Card className="state-panel" role="status" aria-live="polite">
      <CardHeader>
        <span className="state-icon" aria-hidden="true">{result.state === "connected" ? <Activity size={18} /> : <CircleHelp size={18} />}</span>
        <CardTitle>{result.state === "connected" ? "Aucune donnée" : "Données indisponibles"}</CardTitle>
        <CardDescription>{result.state === "connected" ? "Aucun résultat." : result.message}</CardDescription>
      </CardHeader>
      <CardContent><Button variant="outline" onClick={onReload} type="button">Recharger</Button></CardContent>
    </Card>
  );
}

function Badge({ value }: { value: string }) {
  const negative = /failed|error|inactive|suspended|suspendu|expired|revoked|échoué|inactif|expiré|révoqué/i.test(value);
  return <UiBadge variant={negative ? "destructive" : "secondary"}>{value}</UiBadge>;
}

export default function Home() {
  const [section, setSection] = useState<Section>("dashboard");
  const [sidebarCollapsed, setSidebarCollapsed] = useState(false);
  const [mobileMenuOpen, setMobileMenuOpen] = useState(false);
  const openMobileMenu = useCallback(() => setMobileMenuOpen(true), []);
  const closeMobileMenu = useCallback(() => setMobileMenuOpen(false), []);
  const [dashboard, setDashboard] = useState<ApiResult<DashboardSummary>>(empty);
  const [sites, setSites] = useState<ApiResult<Site[]>>(empty);
  const [plans, setPlans] = useState<ApiResult<Plan[]>>(empty);
  const [routers, setRouters] = useState<ApiResult<Router[]>>(empty);
  const [transactions, setTransactions] = useState<ApiResult<Transaction[]>>(empty);
  const [sessions, setSessions] = useState<ApiResult<{ items: OwnerHotspotSession[] }>>(empty);
  const [paymentSettings, setPaymentSettings] = useState<ApiResult<PaymentSettings>>(empty);
  const [members, setMembers] = useState<ApiResult<{ items: OwnerMember[] }>>(empty);
  const [subscription, setSubscription] = useState<ApiResult<OwnerSubscriptionSummary>>(empty);
  const [organizations, setOrganizations] = useState<ApiResult<OwnerOrganization[]>>(empty);
  const [selectedTenantId, setSelectedTenantId] = useState("");
  const selectedTenantRef = useRef("");
  const reloadSequence = useRef(0);
  const [loading, setLoading] = useState(false);
  const [logoutError, setLogoutError] = useState("");

  const reload = useCallback(async () => {
    const sequence = ++reloadSequence.current;
    setLoading(true);
    const orgs = await api.organizations();
    if (sequence !== reloadSequence.current) return;
    setOrganizations(orgs);
    if (orgs.state !== "connected") {
      setDashboard(orgs);
      setSites(orgs);
      setPlans(orgs);
      setRouters(orgs);
      setTransactions(orgs);
      setSessions(orgs);
      setPaymentSettings(orgs);
      setMembers(orgs);
      setSubscription(orgs);
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
      setSessions(selectionRequired);
      setPaymentSettings(selectionRequired);
      setMembers(selectionRequired);
      setSubscription(selectionRequired);
      setLoading(false);
      return;
    }

    const [d, s, p, r, t, sessionData, paymentData, memberData, subscriptionData] = await Promise.all([
      api.dashboard(), api.sites(), api.plans(), api.routers(), api.transactions(), api.sessions(), api.paymentSettings(), api.members(), api.subscription(),
    ]);
    if (sequence !== reloadSequence.current) return;
    setDashboard(d);
    setSites(s);
    setPlans(p);
    setRouters(r);
    setTransactions(t);
    setSessions(sessionData);
    setPaymentSettings(paymentData);
    setMembers(memberData);
    setSubscription(subscriptionData);
    setLoading(false);
  }, []);

  useEffect(() => {
    const timer = window.setTimeout(() => { void reload(); }, 0);
    return () => window.clearTimeout(timer);
  }, [reload]);

  function selectSection(next: Section) {
    setSection(next);
    const url = new URL(window.location.href);
    url.searchParams.set("section", next);
    window.history.pushState(null, "", url);
  }

  useEffect(() => {
    const readSection = () => {
      const candidate = new URLSearchParams(window.location.search).get("section");
      if (candidate && Object.prototype.hasOwnProperty.call(titles, candidate)) setSection(candidate as Section);
    };
    readSection();
    window.addEventListener("popstate", readSection);
    return () => window.removeEventListener("popstate", readSection);
  }, []);

  const toggleSidebar = () => setSidebarCollapsed((collapsed) => !collapsed);
  async function logout() {
    setLogoutError("");
    try {
      await api.logout();
      window.location.assign(new URL(CENTRAL_LOGIN_URL, window.location.origin).toString());
    } catch (cause) {
      setLogoutError(cause instanceof Error ? cause.message : "Déconnexion impossible.");
    }
  }

  const current = titles[section];
  const portalSites = sites.state === "connected" ? sites.data.filter((site) => site.status === "active") : [];
  const visibleNavigation = navigation.filter((item) => !(item.id === "members" || item.id === "subscription") || orgsRole(organizations, selectedTenantId) === "owner");
  const needsWorkspace = organizations.state === "connected" && organizations.data.length === 0;
  const workspaceLookupFailed = organizations.state === "unavailable" && organizations.message !== "Chargement des données…";

  return (
    <div className="app-shell">
      {!needsWorkspace && <aside className={`sidebar ${sidebarCollapsed ? "is-collapsed" : ""}`}>
        <Button className="sidebar-toggle" variant="outline" size="icon" type="button" onClick={toggleSidebar} aria-label={sidebarCollapsed ? "Déployer la barre latérale" : "Rétracter la barre latérale"} aria-expanded={!sidebarCollapsed} aria-controls="owner-navigation" title={sidebarCollapsed ? "Déployer la barre latérale" : "Rétracter la barre latérale"}>
          {sidebarCollapsed ? <ChevronRight size={16} aria-hidden /> : <ChevronLeft size={16} aria-hidden />}
        </Button>
        <BrandLink onClick={() => selectSection("dashboard")} />
        <div className="workspace-label">ESPACE PROPRIÉTAIRE</div>
        <Separator className="workspace-separator" />
        {needsWorkspace ? <p className="workspace-empty">Créez votre espace pour commencer.</p> : <nav id="owner-navigation" aria-label="Navigation principale">
          <NavigationLinks items={visibleNavigation} current={section} collapsed={sidebarCollapsed} onSelect={selectSection} />
        </nav>}
        <div className="sidebar-bottom"><OwnerProfile /></div>
      </aside>}
      <MobileNavigation open={mobileMenuOpen} onClose={closeMobileMenu} current={section} items={visibleNavigation} onSelect={selectSection} showNavigation={!needsWorkspace} />

      <main className={`main-area ${needsWorkspace ? "is-onboarding" : sidebarCollapsed ? "is-collapsed" : ""}`} id="home" inert={mobileMenuOpen}>
        <header className="topbar">
          {needsWorkspace ? <BrandLink onClick={() => selectSection("dashboard")} /> : <div className="topbar-start"><MobileMenuButton open={mobileMenuOpen} onOpen={openMobileMenu} /><div className="breadcrumbs"><span>Espace propriétaire</span><span className="crumb-sep">/</span><strong>{current.title}</strong></div></div>}
          <div className="top-actions"><Button variant="outline" size="sm" type="button" onClick={() => void logout()}>Déconnexion</Button></div>
        </header>

        <div className={`content ${needsWorkspace ? "onboarding-content" : ""}`}>
          {logoutError && <p role="alert">{logoutError}</p>}
          {needsWorkspace ? <>
            <section className="page-heading onboarding-heading"><div><h1>Créer mon espace Miksprit</h1><p>Commencez par nommer votre organisation. Vous pourrez ajouter votre premier site Wi-Fi juste après.</p></div></section>
            <CreateWorkspaceView onCreated={reload} />
          </> : workspaceLookupFailed ? <>
            <section className="page-heading"><div><h1>Espace indisponible</h1><p>Vérifiez votre connexion puis réessayez.</p></div><Button onClick={() => void reload()} disabled={loading}>{loading ? "Vérification…" : "Réessayer"}</Button></section>
            <p role="status" aria-live="polite">{organizations.message}</p>
          </> : <>
          <section className="page-heading"><div><h1>{current.title}</h1><p>{current.description}</p></div><div className="heading-actions"><label className="tenant-picker-label" htmlFor="owner-tenant">Organisation</label><select id="owner-tenant" className="tenant-picker" value={selectedTenantId} disabled={organizations.state !== "connected" || organizations.data.length < 2} onChange={(event) => { const id = event.currentTarget.value; selectedTenantRef.current = id; setOwnerTenant(id || null); setSelectedTenantId(id); selectSection("dashboard"); void reload(); }} aria-label="Organisation active"><option value="">Choisir une organisation</option>{organizations.state === "connected" && organizations.data.map((organization) => <option key={organization.id} value={organization.id}>{organization.name} · {organization.role}</option>)}</select>{portalSites.length === 1 ? <Button asChild variant="outline"><a href={`/portal/?siteId=${encodeURIComponent(portalSites[0].id)}`} target="_blank" rel="noopener noreferrer">Portail captif</a></Button> : <Button variant="outline" type="button" disabled={portalSites.length === 0} onClick={() => selectSection("sites")}>{portalSites.length > 1 ? "Choisir un site" : "Portail captif"}</Button>}<Button onClick={() => void reload()} disabled={loading}><span aria-hidden="true">↻</span>{loading ? "Actualisation…" : "Actualiser"}</Button></div></section>

          {section === "dashboard" && <DashboardView result={dashboard} reload={() => void reload()} />}
          {section === "sites" && <SitesView result={sites} reload={() => void reload()} />}
          {section === "plans" && <PlansView result={plans} sites={sites} reload={() => void reload()} />}
          {section === "routers" && <RoutersView result={routers} sites={sites} reload={() => void reload()} />}
          {section === "sessions" && <SessionsView result={sessions} reload={() => void reload()} />}
          {section === "transactions" && <TransactionsView result={transactions} reload={() => void reload()} />}
          {section === "payment-settings" && <PaymentSettingsView result={paymentSettings} reload={() => void reload()} />}
          {section === "members" && <MembershipsView result={members} reload={() => void reload()} />}
          {section === "subscription" && <SubscriptionView result={subscription} reload={() => void reload()} />}
          </>}

          <footer className="content-footer"><span>© 2026 Miksprit</span></footer>
        </div>
      </main>
    </div>
  );
}

type NavigationItem = (typeof navigation)[number];

function BrandLink({ onClick }: { onClick: () => void }) {
  return <a className="brand" href="#home" onClick={onClick}>
    <span className="brand-mark">M</span><span className="brand-name">miksprit</span>
  </a>;
}

function OwnerProfile() {
  return <div className="profile"><div className="profile-copy"><strong>Espace gérant</strong><small>Compte connecté</small></div></div>;
}

function NavigationLinks({ items, current, onSelect, collapsed = false, mobile = false }: { items: NavigationItem[]; current: Section; onSelect: (section: Section) => void; collapsed?: boolean; mobile?: boolean }) {
  return <>{items.map((item) => (
    <Button key={item.id} type="button" variant="ghost" className={`nav-item ${mobile ? "mobile-nav-item" : ""} ${current === item.id ? "active" : ""}`} aria-label={item.label} title={collapsed ? item.label : undefined} aria-current={current === item.id ? "page" : undefined} onClick={() => onSelect(item.id)}>
      <item.icon className="nav-icon" size={18} aria-hidden /><span className="nav-label">{item.label}</span>
      <ChevronRight className="nav-arrow" size={16} aria-hidden />
    </Button>
  ))}</>;
}

function MobileMenuButton({ open, onOpen }: { open: boolean; onOpen: () => void }) {
  return <Button id="owner-mobile-menu-toggle" className="mobile-menu-toggle" variant="outline" size="icon" type="button" aria-label={open ? "Navigation ouverte" : "Ouvrir la navigation"} aria-expanded={open} aria-controls="owner-mobile-navigation" onClick={onOpen}>
    <Menu size={19} aria-hidden />
  </Button>;
}

function MobileNavigation({ open, onClose, current, items, onSelect, showNavigation }: { open: boolean; onClose: () => void; current: Section; items: NavigationItem[]; onSelect: (section: Section) => void; showNavigation: boolean }) {
  const drawerRef = useRef<HTMLElement>(null);
  const wasOpen = useRef(false);

  useEffect(() => {
    if (!open) {
      if (wasOpen.current) {
        wasOpen.current = false;
        window.requestAnimationFrame(() => document.getElementById("owner-mobile-menu-toggle")?.focus());
      }
      return;
    }

    wasOpen.current = true;
    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = "hidden";
    window.requestAnimationFrame(() => {
      const selectedLink = drawerRef.current?.querySelector<HTMLElement>(".mobile-nav-item.active");
      (selectedLink ?? drawerRef.current?.querySelector<HTMLElement>(".mobile-drawer-close"))?.focus();
    });

    function handleKeyboard(event: KeyboardEvent) {
      if (event.key === "Escape") {
        onClose();
        return;
      }
      if (event.key !== "Tab") return;
      const focusable = Array.from(drawerRef.current?.querySelectorAll<HTMLElement>('a[href], button:not(:disabled)') ?? [])
        .filter((element) => element.getClientRects().length > 0);
      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      if (!first || !last) return;
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    }
    function closeAtDesktopWidth() {
      if (window.matchMedia("(min-width: 721px)").matches) onClose();
    }

    window.addEventListener("keydown", handleKeyboard);
    window.addEventListener("resize", closeAtDesktopWidth);
    return () => {
      document.body.style.overflow = previousOverflow;
      window.removeEventListener("keydown", handleKeyboard);
      window.removeEventListener("resize", closeAtDesktopWidth);
    };
  }, [open, onClose]);

  function choose(section: Section) {
    onSelect(section);
    onClose();
  }

  return <>
    <aside ref={drawerRef} id="owner-mobile-navigation" className={`mobile-drawer ${open ? "is-open" : ""}`} role={open ? "dialog" : undefined} aria-modal={open || undefined} aria-label={open ? "Navigation principale" : undefined} aria-hidden={!open} inert={!open}>
      <div className="mobile-drawer-header"><BrandLink onClick={() => choose("dashboard")} /><Button className="mobile-drawer-close" variant="outline" size="icon" type="button" aria-label="Fermer la navigation" onClick={onClose}><X size={19} aria-hidden /></Button></div>
      <p className="mobile-drawer-kicker">ESPACE PROPRIÉTAIRE</p>
      <Separator className="mobile-drawer-separator" />
      {showNavigation ? <nav className="mobile-nav" aria-label="Pages de l’espace propriétaire"><NavigationLinks items={items} current={current} onSelect={choose} mobile /></nav> : <p className="mobile-drawer-empty">Créez votre espace propriétaire pour accéder à ses rubriques.</p>}
      <div className="mobile-drawer-profile"><OwnerProfile /></div>
    </aside>
    {open && <button className="mobile-nav-backdrop" type="button" aria-label="Fermer la navigation" tabIndex={-1} onClick={onClose} />}
  </>;
}

function CreateWorkspaceView({ onCreated }: { onCreated: () => Promise<void> }) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [name, setName] = useState("");
  const [slug, setSlug] = useState("");
  const [slugEdited, setSlugEdited] = useState(false);

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

  return <Card className="workspace-create-card">
    <CardHeader className="workspace-create-header">
      <CardTitle>Votre organisation</CardTitle>
      <CardDescription>Choisissez le nom qui apparaîtra dans votre espace propriétaire.</CardDescription>
    </CardHeader>
    <CardContent className="workspace-create-content">
      <form className="workspace-create-form" onSubmit={(event) => void create(event)}>
        <label htmlFor="organization-name">Nom de l’organisation
          <input id="organization-name" name="name" autoComplete="organization" placeholder="Ex. Café du Centre" maxLength={160} value={name} onChange={(event) => {
            const nextName = event.currentTarget.value;
            setName(nextName);
            if (!slugEdited) setSlug(organizationSlugFromName(nextName));
          }} required />
        </label>
        <label htmlFor="organization-slug">Identifiant de l’espace
          <input id="organization-slug" name="slug" aria-describedby="organization-slug-hint" placeholder="cafe-du-centre" pattern={SITE_SLUG_PATTERN} maxLength={80} value={slug} onChange={(event) => {
            setSlugEdited(true);
            setSlug(event.currentTarget.value.toLowerCase());
          }} required />
        </label>
        <p id="organization-slug-hint" className="workspace-create-hint">Prérempli à partir du nom. Modifiez-le uniquement si cet identifiant est déjà utilisé.</p>
        {error && <p role="alert" className="form-error">{error}</p>}
        <Button type="submit" disabled={busy || name.trim() === "" || slug.trim() === ""}>{busy ? "Création…" : "Créer mon espace"}</Button>
      </form>
    </CardContent>
  </Card>;
}

function DashboardView({ result, reload }: { result: ApiResult<DashboardSummary>; reload: () => void }) {
  if (result.state !== "connected") return <StatePanel result={result} onReload={reload} />;
  const stats = [
    { label: "Sites actifs", value: result.data.sites.toLocaleString("fr-FR"), icon: MapPin },
    { label: "Routeurs", value: result.data.routers.toLocaleString("fr-FR"), icon: RouterIcon },
    { label: "Sessions actives", value: result.data.activeSessions.toLocaleString("fr-FR"), icon: Activity },
    { label: "Ventes du jour", value: money(result.data.todaySalesXof), icon: Wallet },
  ];
  return <section className="stat-grid">{stats.map(({ icon: Icon, ...stat }) => <article className="stat-card" key={stat.label}><div className="stat-label">{stat.label}<Icon size={16} aria-hidden="true"/></div><strong>{stat.value}</strong></article>)}</section>;
}

function SitesView({ result, reload }: { result: ApiResult<Site[]>; reload: () => void }) {
  const [error, setError] = useState("");
  const [creating, setCreating] = useState(false);
  const [saving, setSaving] = useState(false);
  const createInFlight = useRef(false);
  const [editingSite, setEditingSite] = useState<Site | null>(null);
  async function create(form: HTMLFormElement) {
    if (createInFlight.current) return;
    createInFlight.current = true;
    setCreating(true);
    const data = new FormData(form);
    try { await api.createSite({ name: String(data.get("name") ?? "") }); form.reset(); setError(""); reload(); }
    catch (cause) { setError(cause instanceof Error ? cause.message : "Création impossible."); }
    finally { createInFlight.current = false; setCreating(false); }
  }
  async function changeStatus(site: Site) { try { const status = site.status === "active" ? "suspended" : "active"; await api.setSiteStatus(site.id, status); setError(""); reload(); } catch (cause) { setError(cause instanceof Error ? cause.message : "Mise à jour impossible."); } }
  async function rename(event: React.FormEvent<HTMLFormElement>, site: Site) {
    event.preventDefault();
    if (saving) return;
    const form = new FormData(event.currentTarget);
    setSaving(true); setError("");
    try { await api.updateSite(site.id, { name: String(form.get("name") ?? "") }); setEditingSite(null); reload(); }
    catch (cause) { setError(cause instanceof Error ? cause.message : "Modification impossible."); }
    finally { setSaving(false); }
  }
  const siteStatusLabels: Record<string, string> = { draft: "Brouillon", active: "Actif", suspended: "Suspendu" };
  return <>
    {editingSite
      ? <CreatePanel key={`edit-${editingSite.id}`} initiallyOpen title="Modifier le site" description="Mettez à jour le nom de votre site Wi-Fi." error={error} onSubmit={(event) => void rename(event, editingSite)}>
          <label>Nom du site<input name="name" defaultValue={editingSite.name} minLength={1} maxLength={120} required/></label>
          <div className="plan-edit-actions"><Button type="button" variant="outline" onClick={() => { setEditingSite(null); setError(""); }}>Annuler</Button><Button type="submit" disabled={saving}>{saving ? "Enregistrement…" : "Enregistrer"}</Button></div>
        </CreatePanel>
      : <CreatePanel key="create-site" title="Ajouter un site" description="Ajoutez un point Wi-Fi." error={error} onSubmit={(event) => { event.preventDefault(); void create(event.currentTarget); }}>
          <label>Nom du site<input name="name" placeholder="Ex. Café du Centre" minLength={1} maxLength={120} required/></label>
          <Button type="submit" disabled={creating}>{creating ? "Création…" : "Créer le site"}</Button>
        </CreatePanel>}
    {result.state === "connected" && result.data.length > 0
      ? <TablePanel title="Sites" columns={["Nom", "État", "Portail", "Actions"]}>
          {result.data.map((site) => <tr key={site.id}>
            <td><strong>{site.name}</strong></td>
            <td><Badge value={siteStatusLabels[site.status] ?? site.status}/></td>
            <td>{site.status === "active"
              ? <Button asChild size="sm" variant="outline"><a href={`/portal/?siteId=${encodeURIComponent(site.id)}`} target="_blank" rel="noopener noreferrer">Ouvrir</a></Button>
              : site.status === "draft" ? "Après activation" : "Indisponible"}</td>
            <td className="owner-row-actions">
              <Button size="sm" variant="outline" onClick={() => { setError(""); setEditingSite(site); window.scrollTo({ top: 0, behavior: "smooth" }); }}>Modifier</Button>
              <Button size="sm" variant={site.status === "active" ? "outline" : "default"} onClick={() => void changeStatus(site)}>{site.status === "suspended" ? "Réactiver" : site.status === "draft" ? "Activer" : "Suspendre"}</Button>
            </td>
          </tr>)}
        </TablePanel>
      : <StatePanel result={result} onReload={reload} />}
  </>;
}

function PlansView({ result, sites, reload }: { result: ApiResult<Plan[]>; sites: ApiResult<Site[]>; reload: () => void }) {
  const [error, setError] = useState("");
  const [creating, setCreating] = useState(false);
  const [saving, setSaving] = useState(false);
  const [editingPlan, setEditingPlan] = useState<Plan | null>(null);
  const createInFlight = useRef(false);
  async function create(form: HTMLFormElement) {
    if (createInFlight.current) return;
    createInFlight.current = true;
    setCreating(true);
    const data = new FormData(form);
    try { await api.createPlan({ name: String(data.get("name") ?? ""), siteId: String(data.get("siteId") ?? ""), priceXof: Number(data.get("priceXof")), sessionSeconds: Number(data.get("sessionSeconds")), dataLimitBytes: data.get("dataLimitBytes") ? Number(data.get("dataLimitBytes")) : null, deviceLimit: Number(data.get("deviceLimit")) }); form.reset(); setError(""); reload(); }
    catch (cause) { setError(cause instanceof Error ? cause.message : "Création impossible."); }
    finally { createInFlight.current = false; setCreating(false); }
  }
  async function changeStatus(plan: Plan) { try { await api.setPlanStatus(plan.id, plan.status === "published" ? "draft" : "published"); setError(""); reload(); } catch (cause) { setError(cause instanceof Error ? cause.message : "Mise à jour impossible."); } }
  async function update(event: React.FormEvent<HTMLFormElement>, plan: Plan) {
    event.preventDefault();
    if (saving) return;
    const data = new FormData(event.currentTarget);
    setSaving(true); setError("");
    try {
      await api.updatePlan(plan.id, { name: String(data.get("name") ?? ""), priceXof: Number(data.get("priceXof")), sessionSeconds: Number(data.get("sessionSeconds")), dataLimitBytes: data.get("dataLimitBytes") ? Number(data.get("dataLimitBytes")) : null, deviceLimit: Number(data.get("deviceLimit")) });
      setEditingPlan(null); reload();
    } catch (cause) { setError(cause instanceof Error ? cause.message : "Modification impossible."); }
    finally { setSaving(false); }
  }
  return <>
    {editingPlan
      ? <CreatePanel key={`edit-${editingPlan.id}`} initiallyOpen title="Modifier le forfait" description="Les changements s’appliquent aux prochaines ventes." error={error} onSubmit={(event) => void update(event, editingPlan)}>
          <label>Nom du forfait<input name="name" defaultValue={editingPlan.name} maxLength={120} required/></label>
          <label>Prix (XOF)<input name="priceXof" type="number" min="1" step="1" defaultValue={editingPlan.priceXof} required/></label>
          <label>Durée (secondes)<input name="sessionSeconds" type="number" min="1" defaultValue={editingPlan.sessionSeconds} required/></label>
          <label>Limite de données (octets)<input name="dataLimitBytes" type="number" min="1" defaultValue={editingPlan.dataLimitBytes ?? ""}/></label>
          <label>Appareils autorisés<input name="deviceLimit" type="number" min="1" defaultValue={editingPlan.deviceLimit} required/></label>
          <div className="plan-edit-actions"><Button type="button" variant="outline" onClick={() => { setEditingPlan(null); setError(""); }}>Annuler</Button><Button type="submit" disabled={saving}>{saving ? "Enregistrement…" : "Enregistrer"}</Button></div>
        </CreatePanel>
      : <CreatePanel key="create-plan" title="Créer un forfait" description="Prix et limites enregistrés à chaque commande." error={error} onSubmit={(event) => { event.preventDefault(); void create(event.currentTarget); }}>
          <label>Nom du forfait<input name="name" placeholder="Ex. 1 heure" maxLength={120} required/></label>
          <label>Site<select name="siteId" required defaultValue="">{sites.state === "connected" && sites.data.map((site) => <option key={site.id} value={site.id}>{site.name}</option>)}</select></label>
          <label>Prix (XOF)<input name="priceXof" placeholder="Ex. 500" type="number" min="1" step="1" required/></label>
          <label>Durée (secondes)<input name="sessionSeconds" placeholder="Ex. 3600" type="number" min="1" required/></label>
          <label>Limite de données (octets)<input name="dataLimitBytes" placeholder="Facultative" type="number" min="1"/></label>
          <label>Appareils autorisés<input name="deviceLimit" type="number" min="1" defaultValue="1" required/></label>
          <Button type="submit" disabled={creating || sites.state !== "connected" || sites.data.length === 0}>{creating ? "Création…" : "Créer le forfait"}</Button>
        </CreatePanel>}
    {result.state === "connected" && result.data.length > 0
      ? <TablePanel title="Forfaits" columns={["Nom", "Prix", "Durée", "État", "Actions"]}>
          {result.data.map((plan) => <tr key={plan.id}>
            <td><strong>{plan.name}</strong></td><td>{money(plan.priceXof)}</td><td>{plan.durationLabel}</td><td><Badge value={plan.status}/></td>
            <td className="owner-row-actions">{plan.status !== "archived" && <>
              <Button size="sm" variant="outline" onClick={() => { setError(""); setEditingPlan(plan); window.scrollTo({ top: 0, behavior: "smooth" }); }}>Modifier</Button>
              <Button size="sm" variant="outline" onClick={() => void changeStatus(plan)}>{plan.status === "published" ? "Dépublier" : "Publier"}</Button>
            </>}</td>
          </tr>)}
        </TablePanel>
      : <StatePanel result={result} onReload={reload}/>}
  </>;
}

function RoutersView({ result, sites, reload }: { result: ApiResult<Router[]>; sites: ApiResult<Site[]>; reload: () => void }) {
  const [error, setError] = useState("");
  const [creating, setCreating] = useState(false);
  const [savingCredentials, setSavingCredentials] = useState(false);
  const [editingCredentialsRouter, setEditingCredentialsRouter] = useState<Router | null>(null);
  const createInFlight = useRef(false);
  const [drivers, setDrivers] = useState<ApiResult<RouterDriver[]>>(empty);
  useEffect(() => { let active = true; void api.routerDrivers().then((value) => { if (active) setDrivers(value); }); return () => { active = false; }; }, []);
  async function create(form: HTMLFormElement) {
    if (createInFlight.current) return;
    createInFlight.current = true;
    setCreating(true);
    const data = new FormData(form);
    try { await api.createRouter({ name: String(data.get("name") ?? ""), siteId: String(data.get("siteId") ?? ""), host: String(data.get("host") ?? ""), apiPort: Number(data.get("apiPort")), username: String(data.get("username") ?? ""), password: String(data.get("password") ?? ""), providerCode: String(data.get("providerCode") ?? "") }); form.reset(); setError(""); reload(); }
    catch (cause) { setError(cause instanceof Error ? cause.message : "Enrôlement impossible."); }
    finally { createInFlight.current = false; setCreating(false); }
  }
  async function suspend(router: Router) { try { await api.suspendRouter(router.id); setError(""); reload(); } catch (cause) { setError(cause instanceof Error ? cause.message : "Mise à jour impossible."); } }
  async function verify(router: Router) { try { await api.verifyRouter(router.id); setError(""); reload(); } catch (cause) { setError(cause instanceof Error ? cause.message : "Le contrôle du routeur a échoué."); } }
  async function rotate(event: React.FormEvent<HTMLFormElement>, router: Router) {
    event.preventDefault();
    if (savingCredentials) return;
    const data = new FormData(event.currentTarget);
    setSavingCredentials(true); setError("");
    try { await api.replaceRouterCredentials(router.id, { username: String(data.get("username") ?? ""), password: String(data.get("password") ?? "") }); setEditingCredentialsRouter(null); reload(); }
    catch (cause) { setError(cause instanceof Error ? cause.message : "Rotation impossible."); }
    finally { setSavingCredentials(false); }
  }
  async function revoke(router: Router) { try { await api.revokeRouterCredentials(router.id); setError(""); reload(); } catch (cause) { setError(cause instanceof Error ? cause.message : "Révocation impossible."); } }
  return <>
    {editingCredentialsRouter
      ? <CreatePanel key={`credentials-${editingCredentialsRouter.id}`} initiallyOpen title="Remplacer les identifiants" description={`${editingCredentialsRouter.name} · ${editingCredentialsRouter.siteName}`} error={error} onSubmit={(event) => void rotate(event, editingCredentialsRouter)}>
          <label>Nouvel utilisateur API<input name="username" aria-label={`Nouvel utilisateur API ${editingCredentialsRouter.name}`} placeholder="Nouvel utilisateur API" maxLength={190} required/></label>
          <label>Nouveau mot de passe API<input name="password" aria-label={`Nouveau mot de passe API ${editingCredentialsRouter.name}`} placeholder="Nouveau mot de passe API" type="password" maxLength={500} required/></label>
          <div className="plan-edit-actions"><Button type="button" variant="outline" onClick={() => { setEditingCredentialsRouter(null); setError(""); }}>Annuler</Button><Button type="submit" disabled={savingCredentials}>{savingCredentials ? "Enregistrement…" : "Enregistrer"}</Button></div>
        </CreatePanel>
      : <CreatePanel key="create-router" title="Enregistrer un routeur" description="Identifiants chiffrés. Activation après vérification TLS." error={error} onSubmit={(event) => { event.preventDefault(); void create(event.currentTarget); }}>
          <label>Nom du routeur<input name="name" aria-label="Nom du routeur" placeholder="Nom du routeur" maxLength={120} required/></label>
          <label>Site<select name="siteId" aria-label="Site" required defaultValue="">{sites.state === "connected" && sites.data.map((site) => <option key={site.id} value={site.id}>{site.name}</option>)}</select></label>
          <label>Kit Wi-Fi<select name="providerCode" aria-label="Kit Wi-Fi" required defaultValue="mikrotik" disabled={drivers.state !== "connected"}>{drivers.state === "connected" && drivers.data.map((driver) => <option key={driver.code} value={driver.code}>{driver.code}</option>)}</select></label>
          <label>Hôte ou adresse IP<input name="host" aria-label="Hôte ou adresse IP" placeholder="Hôte ou adresse IP autorisé" maxLength={253} required/></label>
          <label>Port API TLS<input name="apiPort" aria-label="Port API TLS" placeholder="Port API TLS" type="number" min="1" max="65535" defaultValue="8729" required/></label>
          <label>Utilisateur API RouterOS<input name="username" aria-label="Utilisateur API routeur" placeholder="Utilisateur API RouterOS" maxLength={190} required/></label>
          <label>Mot de passe API RouterOS<input name="password" aria-label="Mot de passe API routeur" placeholder="Mot de passe API RouterOS" type="password" maxLength={500} required/></label>
          <Button type="submit" disabled={creating || sites.state !== "connected" || sites.data.length === 0 || drivers.state !== "connected" || drivers.data.length === 0}>{creating ? "Enregistrement…" : "Enregistrer le routeur"}</Button>
        </CreatePanel>}
    {result.state === "connected" && result.data.length > 0
      ? <TablePanel title="Routeurs enregistrés" columns={["Nom", "Site", "Kit", "Version", "État", "Actions"]}>
          {result.data.map((router) => <tr key={router.id}>
            <td><strong>{router.name}</strong></td><td>{router.siteName}</td><td>{router.providerCode}</td><td>{router.version || "—"}</td><td><Badge value={router.status} /></td>
            <td className="owner-row-actions">{router.status === "active"
              ? <Button size="sm" variant="outline" onClick={() => void suspend(router)}>Suspendre</Button>
              : <><Button size="sm" variant="outline" onClick={() => void verify(router)}>Vérifier et activer</Button><Button size="sm" variant="outline" onClick={() => { setEditingCredentialsRouter(router); setError(""); window.scrollTo({ top: 0, behavior: "smooth" }); }}>Remplacer les identifiants</Button><Button size="sm" variant="destructive" onClick={() => void revoke(router)}>Révoquer</Button></>}</td>
          </tr>)}
        </TablePanel>
      : <StatePanel result={result} onReload={reload} />}
  </>;
}
function MembershipsView({ result, reload }: { result: ApiResult<{ items: OwnerMember[] }>; reload: () => void }) {
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);
  const roles: Record<string, string> = { owner: "Propriétaire", manager: "Gestionnaire", operator: "Opérateur", analyst: "Analyste" };
  const statuses: Record<string, string> = { active: "Actif", invited: "Invitation en attente", suspended: "Suspendu", revoked: "Révoqué" };

  async function invite(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = event.currentTarget;
    const data = new FormData(form);
    setBusy(true); setError("");
    try { await api.inviteMember({ email: String(data.get("email") ?? ""), role: String(data.get("role") ?? "operator") }); form.reset(); reload(); }
    catch (cause) { setError(cause instanceof Error ? cause.message : "Invitation impossible."); }
    finally { setBusy(false); }
  }

  async function setStatus(member: OwnerMember, status: string) {
    setBusy(true); setError("");
    try { await api.setMemberStatus(member.id, status); reload(); }
    catch (cause) { setError(cause instanceof Error ? cause.message : "Mise à jour impossible."); }
    finally { setBusy(false); }
  }

  async function setRole(member: OwnerMember, role: string) {
    setBusy(true); setError("");
    try { await api.setMemberRole(member.id, role); reload(); }
    catch (cause) { setError(cause instanceof Error ? cause.message : "Mise à jour impossible."); }
    finally { setBusy(false); }
  }

  return <>
    <CreatePanel title="Inviter un membre" description="Invitation par e-mail à cette organisation." error={error} onSubmit={(event) => void invite(event)}>
      <label>Adresse e-mail<input name="email" type="email" placeholder="nom@exemple.com" maxLength={190} required /></label>
      <label>Rôle<select name="role" defaultValue="operator"><option value="manager">Gestionnaire</option><option value="operator">Opérateur</option><option value="analyst">Analyste</option></select></label>
      <Button type="submit" disabled={busy}>{busy ? "Envoi…" : "Envoyer l’invitation"}</Button>
    </CreatePanel>
    {result.state === "connected" && result.data.items.length > 0
      ? <TablePanel title="Membres de l’organisation" columns={["Adresse e-mail", "Rôle", "État", "Actions"]}>{result.data.items.map((member) => <tr key={member.id}><td><strong>{member.email}</strong></td><td>{member.role === "owner" ? roles.owner : <select aria-label={`Rôle de ${member.email}`} value={member.role} disabled={busy || member.status === "revoked" || member.status === "suspended"} onChange={(event) => void setRole(member, event.currentTarget.value)}><option value="manager">Gestionnaire</option><option value="operator">Opérateur</option><option value="analyst">Analyste</option></select>}</td><td><Badge value={statuses[member.status] ?? member.status} /></td><td className="owner-row-actions">{member.role !== "owner" && <>{member.status === "invited" && <Button size="sm" variant="outline" disabled={busy} onClick={() => void api.inviteMember({ email: member.email, role: member.role }).then(reload).catch((cause) => setError(cause instanceof Error ? cause.message : "Renvoi impossible."))}>Renvoyer</Button>}{member.status === "active" && <Button size="sm" variant="outline" disabled={busy} onClick={() => void setStatus(member, "suspended")}>Suspendre</Button>}{member.status === "suspended" && <Button size="sm" variant="outline" disabled={busy} onClick={() => void setStatus(member, "active")}>Réactiver</Button>}{member.status !== "revoked" && <Button size="sm" variant="destructive" disabled={busy} onClick={() => void setStatus(member, "revoked")}>Révoquer</Button>}</>}</td></tr>)}</TablePanel>
      : <StatePanel result={result} onReload={reload} />}
    {error && result.state === "connected" && <p role="alert" className="form-error">{error}</p>}
  </>;
}

function SubscriptionView({ result, reload }: { result: ApiResult<OwnerSubscriptionSummary>; reload: () => void }) {
  const [busyOffer, setBusyOffer] = useState("");
  const [message, setMessage] = useState("");
  const [error, setError] = useState("");
  const labels: Record<string, string> = { pending: "Paiement en attente", active: "Actif", past_due: "En retard", expired: "Expiré", suspended: "Suspendu", canceled: "Annulé" };
  const fmtDate = (value: string | null) => value ? new Intl.DateTimeFormat("fr-FR", { dateStyle: "medium" }).format(new Date(value)) : "—";

  async function checkout(offerId: string) {
    setBusyOffer(offerId); setError(""); setMessage("");
    try {
      const payment = await api.checkoutSubscription(offerId);
      if (payment.checkoutUrl) window.location.assign(payment.checkoutUrl);
      else setMessage("Paiement créé. En attente de confirmation.");
      reload();
    } catch (cause) { setError(cause instanceof Error ? cause.message : "Le paiement d’abonnement n’a pas pu démarrer."); }
    finally { setBusyOffer(""); }
  }

  if (result.state !== "connected") return <StatePanel result={result} onReload={reload} />;
  const data = result.data.subscription;
  return <>
    <Card className="panel"><CardHeader><CardTitle>État de l’abonnement</CardTitle><CardDescription>Facturation Miksprit, séparée des tickets Wi-Fi.</CardDescription></CardHeader><CardContent>
      {data.subscription ? <dl className="subscription-summary"><div><dt>État</dt><dd><Badge value={labels[data.subscription.status] ?? data.subscription.status} /></dd></div><div><dt>Offre</dt><dd>{data.subscription.offerName} · {money(data.subscription.amountXof)}</dd></div><div><dt>Fin de période</dt><dd>{fmtDate(data.subscription.currentPeriodEnd)}</dd></div><div><dt>Prochaine échéance</dt><dd>{fmtDate(data.subscription.nextDueAt)}</dd></div></dl>
        : <p>Aucun abonnement n’est associé à cette organisation.</p>}
      {!data.billingEnabled && <p role="status">Le paiement de l’abonnement n’est pas encore configuré par l’administration Miksprit.</p>}
      {error && <p role="alert" className="form-error">{error}</p>}{message && <p role="status" aria-live="polite">{message}</p>}
    </CardContent></Card>
    <Card className="panel"><CardHeader><CardTitle>Offres disponibles</CardTitle><CardDescription>Tarifs Miksprit, sans commission sur les tickets.</CardDescription></CardHeader><CardContent>
      {data.offers.length === 0 ? <p>Aucune offre d’abonnement n’est publiée pour le moment.</p> : <div className="table-scroll"><table><thead><tr><th>Offre</th><th>Tarif</th><th>Période</th><th>Grâce</th><th>Action</th></tr></thead><tbody>{data.offers.map((offer) => <tr key={offer.id}><td><strong>{offer.name}</strong></td><td>{money(offer.amountXof)}</td><td>{offer.intervalDays} jours</td><td>{offer.graceDays ? `${offer.graceDays} jours` : "Aucune"}</td><td><Button size="sm" disabled={!data.billingEnabled || busyOffer !== ""} onClick={() => void checkout(offer.id)}>{busyOffer === offer.id ? "Préparation…" : data.subscription ? "Renouveler / changer d’offre" : "Choisir cette offre"}</Button></td></tr>)}</tbody></table></div>}
    </CardContent></Card>
    {data.payments.length > 0 && <TablePanel title="Historique des paiements Miksprit" columns={["Référence", "Montant", "État", "Créé le", "Payé le"]}>{data.payments.map((payment) => <tr key={payment.id}><td><strong>{payment.reference}</strong></td><td>{money(payment.amountXof)}</td><td><Badge value={labels[payment.status] ?? payment.status} /></td><td>{fmtDate(payment.createdAt)}</td><td>{fmtDate(payment.paidAt)}</td></tr>)}</TablePanel>}
  </>;
}

function CreatePanel({ title, description, error, onSubmit, children, initiallyOpen = false }: { title: string; description: string; error: string; onSubmit: (event: React.FormEvent<HTMLFormElement>) => void; children: React.ReactNode; initiallyOpen?: boolean }) {
  const [open, setOpen] = useState(initiallyOpen);
  const formId = useId();
  return <section className="panel create-panel">
    <div className="panel-head">
      <div><h2>{title}</h2>{open && <p>{description}</p>}</div>
      {!initiallyOpen && <Button type="button" size="sm" variant={open ? "outline" : "default"} aria-expanded={open} aria-controls={open ? formId : undefined} onClick={() => setOpen((value) => !value)}>{open ? "Fermer" : "Ajouter"}</Button>}
    </div>
    {open && <form id={formId} className="owner-create-form" onSubmit={onSubmit}>{children}</form>}
    {error && <p role="alert" className="form-error">{error}</p>}
  </section>;
}

function SessionsView({ result, reload }: { result: ApiResult<{ items: OwnerHotspotSession[] }>; reload: () => void }) {
  if (result.state !== "connected") return <StatePanel result={result} onReload={reload} />;
  if (result.data.items.length === 0) return <StatePanel result={{ state: "connected", data: [], updatedAt: result.updatedAt }} onReload={reload} />;
  return <TablePanel title="Sessions Wi-Fi récentes" columns={["Site", "Routeur", "Voucher", "État", "Début observé", "Fin", "Entrée / Sortie"]}>{result.data.items.map((session) => <tr key={session.id}><td>{session.siteName}</td><td>{session.routerName}</td><td><code>{session.voucherId}</code></td><td><Badge value={session.status} /></td><td>{session.startedAt ? new Date(`${session.startedAt.replace(" ", "T")}Z`).toLocaleString("fr-FR") : "—"}</td><td>{session.endedAt ? new Date(`${session.endedAt.replace(" ", "T")}Z`).toLocaleString("fr-FR") : "—"}</td><td>{session.bytesIn ?? "—"} / {session.bytesOut ?? "—"} octets</td></tr>)}</TablePanel>;
}

function TransactionsView({ result, reload }: { result: ApiResult<Transaction[]>; reload: () => void }) {
  if (result.state !== "connected" || result.data.length === 0) return <StatePanel result={result} onReload={reload} />;
  const settlementLabels: Record<string, string> = { direct_to_merchant: "Compte marchand du propriétaire", awaiting_payment: "Paiement en attente", awaiting_settlement: "Ancien règlement en attente", available: "Ancien solde disponible", needs_review: "Ancienne vente à rapprocher" };
  return <TablePanel title="Transactions" columns={["Référence", "Payé", "Frais PSP connus", "Commission historique", "Ancien solde", "Encaissement", "État"]}>{result.data.map((transaction) => <tr key={transaction.id}><td><strong>{transaction.reference}</strong></td><td>{money(transaction.amountXof)}</td><td>{transaction.providerFeeXof === null ? "—" : money(transaction.providerFeeXof)}</td><td>{transaction.commissionXof === null ? "—" : money(transaction.commissionXof)}</td><td>{transaction.operatorNetXof === null ? "—" : money(transaction.operatorNetXof)}</td><td><Badge value={settlementLabels[transaction.settlementStatus] ?? transaction.settlementStatus} /></td><td><Badge value={transaction.status} /></td></tr>)}</TablePanel>;
}

function TablePanel({ title, columns, children }: { title: string; columns: string[]; children: React.ReactNode }) {
  return <section className="panel table-panel"><div className="panel-head"><h2>{title}</h2></div><div className="table-scroll"><table><thead><tr>{columns.map((column) => <th key={column}>{column}</th>)}</tr></thead><tbody>{children}</tbody></table></div></section>;
}

function PaymentSettingsView({ result, reload }: { result: ApiResult<PaymentSettings>; reload: () => void }) {
  const [apiKey, setApiKey] = useState("");
  const [webhookSecret, setWebhookSecret] = useState("");
  const [environment, setEnvironment] = useState<"test" | "live">("test");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [message, setMessage] = useState("");
  const [testStatus, setTestStatus] = useState("");
  const configured = result.state === "connected" && result.data.configuration.configured;
  const currentEnvironment: "test" | "live" = configured && result.state === "connected"
    ? result.data.configuration.environment === "live" ? "live" : "test"
    : environment;

  async function save(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault(); setBusy(true); setError(""); setMessage(""); setTestStatus("");
    try {
      await api.savePaymentSettings({ provider: "fedapay", environment: currentEnvironment, apiKey, webhookSecret });
      setApiKey(""); setWebhookSecret("");
      setMessage("Identifiants enregistrés et chiffrés.");
      reload();
    } catch (cause) { setError(cause instanceof Error ? cause.message : "Impossible d’enregistrer la configuration."); }
    finally { setBusy(false); }
  }

  async function testCredentials() {
    setBusy(true); setError(""); setMessage(""); setTestStatus("");
    try {
      await api.testPaymentSettings({ provider: "fedapay", environment: currentEnvironment, apiKey, webhookSecret });
      setTestStatus("Clé valide. Vérifiez le webhook à sa première notification.");
    } catch (cause) { setError(cause instanceof Error ? cause.message : "Test de connexion impossible."); }
    finally { setBusy(false); }
  }

  async function revokeCredentials() {
    if (!window.confirm("Révoquer les identifiants marchands de cette organisation ? Les paiements en cours doivent d’abord être terminés.")) return;
    setBusy(true); setError(""); setMessage("");
    try {
      await api.revokePaymentSettings();
      setMessage("Identifiants marchands révoqués.");
      reload();
    } catch (cause) { setError(cause instanceof Error ? cause.message : "Révocation impossible."); }
    finally { setBusy(false); }
  }

  if (result.state === "unavailable") return <StatePanel result={result} onReload={reload} />;
  return <section className="payment-settings">
    <header className="payment-settings-heading">
      <div><p className="payment-eyebrow">ENCAISSEMENTS</p><h2>Compte marchand</h2><p>Recevez les paiements des tickets avec FedaPay.</p></div>
      <Badge value={configured ? "Identifiants enregistrés" : "À configurer"} />
    </header>

    <div className={`payment-status ${configured && result.state === "connected" && result.data.providerEnabled ? "is-ready" : ""}`} role="status">
      <strong>{configured ? "Configuration FedaPay" : "Avant de commencer"}</strong>
      <p>{configured && result.state === "connected"
        ? `Environnement ${result.data.configuration.environment === "live" ? "production" : "test"}${result.data.configuration.lastApiCheckAt ? ` · clé testée ${new Date(result.data.configuration.lastApiCheckAt).toLocaleDateString("fr-FR")}` : ""}. Secrets chiffrés.${result.data.providerEnabled ? " Encaissements actifs." : " Activation en attente."}`
        : "FedaPay uniquement. Créez votre compte marchand. Kkiapay n’est pas disponible."}</p>
    </div>

    <form className="payment-form" onSubmit={(event) => void save(event)}>
      <div className="payment-form-title"><h3>Identifiants de paiement</h3><p>Secrets masqués et chiffrés.</p></div>
      <div className="payment-fields">
        <label htmlFor="payment-provider">Fournisseur<select id="payment-provider" value="fedapay" disabled><option value="fedapay">FedaPay</option></select></label>
        <label htmlFor="merchant-environment">Environnement<select id="merchant-environment" value={currentEnvironment} disabled={configured} onChange={(event) => setEnvironment(event.currentTarget.value === "live" ? "live" : "test")}><option value="test">Test / sandbox</option><option value="live">Production</option></select><small>Utilisez le même environnement pour les clés et le webhook.</small></label>
        <label htmlFor="merchant-api-key">Clé API privée<input id="merchant-api-key" type="password" autoComplete="new-password" value={apiKey} onChange={(event) => { setApiKey(event.currentTarget.value); setTestStatus(""); }} required maxLength={500} placeholder="Collez votre clé API FedaPay" /></label>
        <label htmlFor="merchant-webhook-secret">Secret de signature webhook<input id="merchant-webhook-secret" type="password" autoComplete="new-password" value={webhookSecret} onChange={(event) => { setWebhookSecret(event.currentTarget.value); setTestStatus(""); }} required maxLength={500} placeholder="Collez le secret de l’endpoint webhook" /></label>
      </div>
      {error && <p role="alert" className="form-error payment-feedback">{error}</p>}{message && <p role="status" className="payout-notice payment-feedback">{message}</p>}{testStatus && <p role="status" className="payout-notice payment-feedback">{testStatus}</p>}
      <div className="payment-actions"><Button type="button" variant="outline" disabled={busy || !apiKey || !webhookSecret} onClick={() => void testCredentials()}>{busy ? "Vérification…" : "Tester les identifiants"}</Button><Button type="submit" disabled={busy}>{busy ? "Enregistrement…" : configured ? "Remplacer les identifiants" : "Enregistrer les identifiants"}</Button>{configured && <Button type="button" variant="outline" disabled={busy} onClick={() => void revokeCredentials()}>Révoquer</Button>}</div>
    </form>

    <aside className="payment-webhook"><div><h3>Webhook FedaPay</h3><p>Ajoutez cette adresse dans votre compte marchand et remplacez le domaine.</p></div><code>/api/v1/webhooks/fedapay</code></aside>
  </section>;
}
