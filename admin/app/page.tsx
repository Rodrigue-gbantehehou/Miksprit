"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import type { ReactNode } from "react";
import { RefreshCw } from "lucide-react";
import { api, type ApiResult, type AdminUser, type AuditEvent, type LedgerEntry, type OperationalStatus, type Payment, type PlatformSummary, type PlatformSubscription, type Router, type SubscriptionOffer, type Tenant } from "@/lib/api";
import { Badge as UiBadge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { AdminMobileMenu, AdminMobileMenuTrigger, AdminSidebar, adminSections, type AdminSection } from "@/components/admin-navigation";

const labels: Record<AdminSection, string> = { overview: "Vue d’ensemble", tenants: "Organisations", routers: "Routeurs", payments: "Paiements", ledger: "Journal financier", audit: "Journal de sécurité", operations: "Suivi des services", users: "Comptes administrateurs", subscriptions: "Abonnements" };
const descriptions: Record<AdminSection, string> = {
  overview: "Activité et état général de Miksprit.",
  tenants: "Organisations inscrites sur la plateforme.",
  routers: "Routeurs utilisés par les organisations.",
  payments: "Suivi des paiements réalisés sur la plateforme.",
  ledger: "Historique des mouvements financiers.",
  audit: "Actions sensibles effectuées sur la plateforme.",
  operations: "État des services qui font fonctionner Miksprit.",
  users: "Comptes ayant accès à l’administration.",
  subscriptions: "Offres Miksprit et abonnements des organisations.",
};
const unavailable = <T,>(): ApiResult<T> => ({ state: "unavailable", message: "Chargement en cours…" });
const money = (value: number) => `${new Intl.NumberFormat("fr-FR", { maximumFractionDigits: 0 }).format(value)} XOF`;

function StatusPanel({ result, reload, title }: { result: ApiResult<unknown>; reload: () => void; title?: string }) {
  return <Card className="empty-state" role="status" aria-live="polite"><div className="empty-symbol" aria-hidden="true"><RefreshCw size={18}/></div><CardTitle>{title ?? (result.state === "connected" ? "Rien à afficher pour le moment" : "Données indisponibles")}</CardTitle><CardDescription>{result.state === "connected" ? "Aucun élément n’est disponible dans cette rubrique." : result.message}</CardDescription><Button onClick={reload} type="button">Actualiser</Button></Card>;
}

export default function GlobalAdmin() {
  const [section, setSection] = useState<AdminSection>("overview");
  const [sidebarCollapsed, setSidebarCollapsed] = useState(false);
  const [mobileMenuOpen, setMobileMenuOpen] = useState(false);
  const mobileMenuRef = useRef<HTMLElement>(null);
  const mobileMenuButtonRef = useRef<HTMLButtonElement>(null);
  const refreshSequence = useRef(0);
  const [data, setData] = useState<{ tenants: ApiResult<Tenant[]>; routers: ApiResult<Router[]>; payments: ApiResult<Payment[]>; ledger: ApiResult<LedgerEntry[]>; audit: ApiResult<AuditEvent[]>; operations: ApiResult<OperationalStatus[]>; users: ApiResult<AdminUser[]>; subscriptionOffers: ApiResult<{ items: SubscriptionOffer[] }>; subscriptions: ApiResult<{ items: PlatformSubscription[] }> }>({ tenants: unavailable(), routers: unavailable(), payments: unavailable(), ledger: unavailable(), audit: unavailable(), operations: unavailable(), users: unavailable(), subscriptionOffers: unavailable(), subscriptions: unavailable() });
  const [summary, setSummary] = useState<ApiResult<PlatformSummary>>(unavailable());
  const [busy, setBusy] = useState(false);
  const [logoutError, setLogoutError] = useState("");

  async function logout() {
    setLogoutError("");
    try {
      await api.logout();
      const loginUrl = process.env.NEXT_PUBLIC_LANDING_LOGIN_URL ?? (process.env.NODE_ENV === "development" ? "http://localhost:3002/connexion" : "/connexion");
      window.location.assign(new URL(loginUrl, window.location.origin).toString());
    } catch (cause) {
      setLogoutError(cause instanceof Error ? cause.message : "Déconnexion impossible.");
    }
  }

  const refresh = useCallback(async () => {
    const sequence = ++refreshSequence.current;
    setBusy(true);
    const [, summary, tenants, routers, payments, ledger, audit, operations, users, subscriptionOffers, subscriptions] = await Promise.all([api.health(), api.summary(), api.tenants(), api.routers(), api.payments(), api.ledger(), api.audit(), api.operations(), api.users(), api.subscriptionOffers(), api.subscriptions()]);
    if (sequence !== refreshSequence.current) return;
    setSummary(summary);
    setData({ tenants, routers, payments, ledger, audit, operations, users, subscriptionOffers, subscriptions });
    setBusy(false);
  }, []);
  useEffect(() => { const timer = window.setTimeout(() => { void refresh(); }, 0); return () => window.clearTimeout(timer); }, [refresh]);
  const reload = () => { void refresh(); };
  const toggleSidebar = () => setSidebarCollapsed((collapsed) => !collapsed);
  function selectSection(id: AdminSection) {
    setSection(id);
    setMobileMenuOpen(false);
    const url = new URL(window.location.href);
    url.searchParams.set("section", id);
    window.history.pushState(null, "", url);
  }

  useEffect(() => {
    const readSection = () => {
      const candidate = new URLSearchParams(window.location.search).get("section");
      if (candidate && adminSections.some((item) => item.id === candidate)) setSection(candidate as AdminSection);
    };
    readSection();
    window.addEventListener("popstate", readSection);
    return () => window.removeEventListener("popstate", readSection);
  }, []);

  useEffect(() => {
    if (!mobileMenuOpen) return;
    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = "hidden";
    window.requestAnimationFrame(() => {
      const target = mobileMenuRef.current?.querySelector<HTMLElement>("button[aria-current='page']")
        ?? mobileMenuRef.current?.querySelector<HTMLElement>("button");
      target?.focus();
    });
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === "Escape") setMobileMenuOpen(false);
      if (event.key !== "Tab") return;
      const elements = Array.from(mobileMenuRef.current?.querySelectorAll<HTMLElement>('button:not(:disabled)') ?? []);
      if (!elements.length) return;
      if (event.shiftKey && document.activeElement === elements[0]) { event.preventDefault(); elements[elements.length - 1].focus(); }
      else if (!event.shiftKey && document.activeElement === elements[elements.length - 1]) { event.preventDefault(); elements[0].focus(); }
    };
    const onResize = () => { if (window.matchMedia("(min-width: 801px)").matches) setMobileMenuOpen(false); };
    window.addEventListener("keydown", onKeyDown);
    window.addEventListener("resize", onResize);
    return () => {
      document.body.style.overflow = previousOverflow;
      window.removeEventListener("keydown", onKeyDown);
      window.removeEventListener("resize", onResize);
      mobileMenuButtonRef.current?.focus();
    };
  }, [mobileMenuOpen]);

  return <div className="admin-shell">
    <AdminSidebar section={section} collapsed={sidebarCollapsed} onSelect={selectSection} onToggle={toggleSidebar}/>
    <AdminMobileMenu open={mobileMenuOpen} section={section} onSelect={selectSection} onClose={() => setMobileMenuOpen(false)} menuRef={mobileMenuRef}/>
    <main className={`work-area ${sidebarCollapsed ? "is-collapsed" : ""}`} id="top" inert={mobileMenuOpen}><header className="top"><div className="top-start"><AdminMobileMenuTrigger open={mobileMenuOpen} buttonRef={mobileMenuButtonRef} onOpen={() => setMobileMenuOpen(true)}/><strong>Administration</strong></div><div className="top-right"><Button variant="outline" size="sm" onClick={reload} disabled={busy}><RefreshCw size={14}/>{busy ? "Actualisation…" : "Actualiser"}</Button><Button variant="outline" size="sm" onClick={() => void logout()}>Se déconnecter</Button></div></header>
      <section className="page-content">{logoutError && <p role="alert">{logoutError}</p>}<div className="heading"><div><h1>{labels[section]}</h1><p>{descriptions[section]}</p></div></div>
        {section === "overview" && <Overview result={summary} reload={reload}/>}
        {section === "tenants" && <List result={data.tenants} reload={reload} title="Organisations" render={(item) => <tr key={item.id}><td><strong>{item.name}</strong></td><td>{item.slug}</td><td>{item.createdAt}</td><td><UiBadge>{item.status}</UiBadge></td></tr>} columns={["Organisation", "Identifiant", "Création", "État"]}/>}
        {section === "routers" && <List result={data.routers} reload={reload} title="Routeurs" render={(item) => <tr key={item.id}><td><strong>{item.name}</strong></td><td>{item.tenantName}</td><td>{item.siteName}</td><td>{item.version ?? "—"}</td><td><UiBadge>{item.status}</UiBadge></td></tr>} columns={["Routeur", "Organisation", "Site", "Version", "État"]}/>}
        {section === "payments" && <List result={data.payments} reload={reload} title="Paiements" render={(item) => <tr key={item.id}><td><strong>{item.reference}</strong></td><td>{item.tenantName}</td><td>{money(item.amountXof)} {item.currency}</td><td>{item.createdAt}</td><td><UiBadge>{item.status}</UiBadge></td></tr>} columns={["Référence", "Organisation", "Montant", "Date", "État"]}/>}
        {section === "ledger" && <List result={data.ledger} reload={reload} title="Journal financier" render={(item) => <tr key={item.id}><td>{item.createdAt}</td><td><strong>{item.tenantName}</strong></td><td>{item.entryType}</td><td>{money(item.amountXof)} {item.currency}</td></tr>} columns={["Date", "Organisation", "Mouvement", "Montant"]}/>}
        {section === "audit" && <List result={data.audit} reload={reload} title="Journal de sécurité" render={(item) => <tr key={item.id}><td>{item.createdAt}</td><td><strong>{item.actor}</strong></td><td>{item.action}</td><td>{item.target}</td></tr>} columns={["Date", "Acteur", "Action", "Cible"]}/>}
        {section === "operations" && <List result={data.operations} reload={reload} title="Suivi des services" render={(item) => <tr key={item.name}><td><strong>{item.name}</strong></td><td><UiBadge>{item.state}</UiBadge></td><td>{item.observedAt}</td></tr>} columns={["Service", "État", "Vérifié le"]}/>}
        {section === "users" && <UsersPanel result={data.users} reload={reload}/>}
        {section === "subscriptions" && <SubscriptionAdminView offers={data.subscriptionOffers} subscriptions={data.subscriptions} reload={reload}/>}
        <footer className="foot"><span>Identifiants sensibles masqués.</span></footer>
      </section>
    </main>
  </div>;
}

function SubscriptionAdminView({ offers, subscriptions, reload }: { offers: ApiResult<{ items: SubscriptionOffer[] }>; subscriptions: ApiResult<{ items: PlatformSubscription[] }>; reload: () => void }) {
  const [editing, setEditing] = useState<SubscriptionOffer | null>(null);
  const [offerFormOpen, setOfferFormOpen] = useState(false);
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState("");
  const [error, setError] = useState("");

  async function save(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const data = new FormData(event.currentTarget);
    setBusy(true); setError(""); setMessage("");
    try {
      await api.saveSubscriptionOffer(editing?.id ?? null, {
        code: String(data.get("code") ?? ""), name: String(data.get("name") ?? ""), provider: String(data.get("provider") ?? "fedapay"),
        amountXof: Number(data.get("amountXof")), intervalDays: Number(data.get("intervalDays")), graceDays: Number(data.get("graceDays") || 0),
        expiredAccessPolicy: "read_only", active: data.get("active") === "on",
      });
      setEditing(null); setOfferFormOpen(false); setMessage("Offre enregistrée."); reload();
    } catch (cause) { setError(cause instanceof Error ? cause.message : "Enregistrement impossible."); }
    finally { setBusy(false); }
  }

  async function changeStatus(item: PlatformSubscription, status: string) {
    setBusy(true); setError(""); setMessage("");
    try { await api.setSubscriptionStatus(item.id, status); setMessage("État de l’abonnement mis à jour."); reload(); }
    catch (cause) { setError(cause instanceof Error ? cause.message : "Mise à jour impossible."); }
    finally { setBusy(false); }
  }

  return <>
    <Card className="table-card"><CardHeader className="table-title subscription-admin-heading"><div><CardTitle>Offres d’abonnement</CardTitle><CardDescription>Configurez les tarifs appliqués aux organisations de la plateforme.</CardDescription></div><Button type="button" variant={offerFormOpen ? "outline" : "default"} aria-expanded={offerFormOpen} onClick={() => { if (offerFormOpen) { setEditing(null); setOfferFormOpen(false); setError(""); setMessage(""); } else { setEditing(null); setOfferFormOpen(true); setError(""); setMessage(""); } }}>{offerFormOpen ? "Fermer" : "Créer une offre"}</Button></CardHeader>
      {offerFormOpen && <CardContent className="subscription-admin-content">
      <div className="subscription-form-intro"><h3>{editing ? "Modifier l’offre" : "Nouvelle offre"}</h3><p>Après expiration, l’accès est limité à la lecture. L’historique reste conservé.</p></div>
      <form id="subscription-offer-form" key={editing?.id ?? "new"} className="subscription-admin-form" onSubmit={(event) => void save(event)}>
        <label htmlFor="offer-code">Code technique<input id="offer-code" name="code" pattern="[a-z0-9][a-z0-9_-]{0,78}[a-z0-9]" maxLength={80} defaultValue={editing?.code ?? ""} autoComplete="off" required /><small>Minuscules, chiffres, tirets ou tirets bas.</small></label>
        <label htmlFor="offer-name">Nom de l’offre<input id="offer-name" name="name" maxLength={120} defaultValue={editing?.name ?? ""} required /></label>
        <label htmlFor="offer-provider">Fournisseur<select id="offer-provider" name="provider" defaultValue={editing?.provider ?? "fedapay"}><option value="fedapay">FedaPay</option></select></label>
        <label htmlFor="offer-amount">Prix (XOF)<input id="offer-amount" name="amountXof" type="number" min="1" step="1" defaultValue={editing?.amountXof ?? ""} required /></label>
        <label htmlFor="offer-period">Période (jours)<input id="offer-period" name="intervalDays" type="number" min="1" max="3650" step="1" defaultValue={editing?.intervalDays ?? ""} required /></label>
        <label htmlFor="offer-grace">Délai de grâce (jours)<input id="offer-grace" name="graceDays" type="number" min="0" max="365" step="1" defaultValue={editing?.graceDays ?? 0} required /><small>Jours accordés après l’échéance avant restriction.</small></label>
        <input name="expiredAccessPolicy" type="hidden" value="read_only" />
        <label className="subscription-active"><input id="offer-active" name="active" type="checkbox" defaultChecked={editing?.active ?? false} /> Offre publiée immédiatement</label>
        <div className="subscription-form-actions"><Button type="submit" disabled={busy}>{busy ? "Enregistrement…" : editing ? "Enregistrer les changements" : "Enregistrer l’offre"}</Button><Button type="button" variant="outline" disabled={busy} onClick={() => { setEditing(null); setOfferFormOpen(false); }}>Annuler</Button></div>
      </form>
      {error && <p role="alert" className="admin-error">{error}</p>}{message && <p role="status" aria-live="polite">{message}</p>}
    </CardContent>}{!offerFormOpen && message && <p className="subscription-admin-message" role="status" aria-live="polite">{message}</p>}</Card>
    <List result={offers.state === "connected" ? { ...offers, data: offers.data.items } : offers} reload={reload} title="Offres enregistrées" columns={["Code", "Offre", "Tarif", "Période", "Grâce", "État", "Action"]} render={(offer) => <tr key={offer.id}><td>{offer.code}</td><td><strong>{offer.name}</strong></td><td>{money(offer.amountXof)}</td><td>{offer.intervalDays} j</td><td>{offer.graceDays} j</td><td><UiBadge>{offer.active ? "Publiée" : "Désactivée"}</UiBadge></td><td><Button size="sm" variant="outline" onClick={() => { setEditing(offer); setOfferFormOpen(true); setError(""); setMessage(""); }}>Modifier</Button></td></tr>} />
    <List result={subscriptions.state === "connected" ? { ...subscriptions, data: subscriptions.data.items } : subscriptions} reload={reload} title="Abonnements par organisation" columns={["Organisation", "Offre", "Montant", "État", "Fin de période", "Action"]} render={(subscription) => <tr key={subscription.id}><td><strong>{subscription.organization}</strong></td><td>{subscription.offer}</td><td>{money(subscription.amountXof)}</td><td><UiBadge>{subscription.status}</UiBadge></td><td>{subscription.currentPeriodEnd ?? "—"}</td><td><select aria-label={`État de ${subscription.organization}`} disabled={busy} value={subscription.status} onChange={(event) => void changeStatus(subscription, event.currentTarget.value)}><option value={subscription.status}>{subscription.status}</option><option value="active">Réactiver</option><option value="suspended">Suspendre</option><option value="canceled">Annuler</option></select></td></tr>} />
  </>;
}

function UsersPanel({ result, reload }: { result: ApiResult<AdminUser[]>; reload: () => void }) {
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState("");

  async function setActive(user: AdminUser, active: boolean) {
    setBusy(true); setMessage("");
    try { await api.setUserActive(user.id, active); setMessage(active ? "Compte activé." : "Compte désactivé."); reload(); }
    catch (error) { setMessage(error instanceof Error ? error.message : "La mise à jour a échoué."); }
    finally { setBusy(false); }
  }

  return <>
    {result.state !== "connected" || result.data.length === 0
      ? <StatusPanel result={result} reload={reload} title={result.state === "connected" ? "Aucun compte administrateur" : "Comptes indisponibles"}/>
      : <Card className="table-card"><CardHeader className="table-title"><div><CardTitle>Comptes de la plateforme</CardTitle><CardDescription>Accès des administrateurs Miksprit.</CardDescription></div><Button variant="secondary" size="sm" onClick={reload}>Actualiser</Button></CardHeader><CardContent className="table-scroll"><table><thead><tr><th>Adresse e-mail</th><th>Rôle</th><th>État</th><th>Actions</th></tr></thead><tbody>{result.data.map((user) => <tr key={user.id}><td><strong>{user.email}</strong></td><td>{user.roles.join(", ")}</td><td><UiBadge>{user.active ? "Actif" : "Désactivé"}</UiBadge></td><td className="user-actions"><Button variant="outline" size="sm" disabled={busy} onClick={() => void setActive(user, !user.active)}>{user.active ? "Désactiver" : "Activer"}</Button></td></tr>)}</tbody></table></CardContent></Card>}
    {message && <p role="status" aria-live="polite">{message}</p>}
  </>;
}

function Overview({ result, reload }: { result: ApiResult<PlatformSummary>; reload: () => void }) {
  if (result.state !== "connected") return <StatusPanel result={result} reload={reload} title="Données indisponibles"/>;
  const metrics = [
    { label: "Organisations actives", value: result.data.tenants.toLocaleString("fr-FR") },
    { label: "Comptes actifs", value: result.data.activeUsers.toLocaleString("fr-FR") },
    { label: "Routeurs actifs", value: result.data.activeRouters.toLocaleString("fr-FR") },
    { label: "Paiements du jour", value: money(result.data.paymentsTodayXof) },
    { label: "Paiements en attente de traitement", value: result.data.pendingOutbox.toLocaleString("fr-FR") },
  ];
  return <div className="overview-metrics">{metrics.map((metric) => <Card className="metric-card" key={metric.label}><CardDescription>{metric.label}</CardDescription><CardTitle>{metric.value}</CardTitle></Card>)}</div>;
}

function List<T>({ result, reload, title, columns, render }: { result: ApiResult<T[]>; reload: () => void; title: string; columns: string[]; render: (item: T) => ReactNode }) {
  if (result.state !== "connected" || result.data.length === 0) return <StatusPanel result={result} reload={reload} title={result.state === "connected" ? "Rien à afficher pour le moment" : "Données indisponibles"}/>;
  return <Card className="table-card"><CardHeader className="table-title"><div><CardTitle>{title}</CardTitle></div><Button variant="secondary" size="sm" onClick={reload}>Actualiser</Button></CardHeader><CardContent className="table-scroll"><table><thead><tr>{columns.map((column) => <th key={column}>{column}</th>)}</tr></thead><tbody>{result.data.map(render)}</tbody></table></CardContent></Card>;
}
