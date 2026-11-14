"use client";

import { useCallback, useEffect, useState } from "react";
import type { FormEvent, ReactNode } from "react";
import { Activity, BookOpenText, Building2, ChevronLeft, ChevronRight, CreditCard, LayoutDashboard, RefreshCw, Router as RouterIcon, ShieldCheck, Users } from "lucide-react";
import { api, type ApiResult, type AdminUser, type AuditEvent, type HealthResponse, type LedgerEntry, type OperationalStatus, type Payment, type PlatformSummary, type Router, type Tenant } from "@/lib/api";
import { Badge as UiBadge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Separator } from "@/components/ui/separator";

const ORGANIZATION_SLUG_PATTERN = "[a-z0-9]([a-z0-9\\x2d]{0,78}[a-z0-9])?";

type Section = "overview" | "tenants" | "routers" | "payments" | "ledger" | "audit" | "operations" | "users";
const sections: { id: Section; label: string; Icon: typeof LayoutDashboard }[] = [
  { id: "overview", label: "Vue globale", Icon: LayoutDashboard },
  { id: "tenants", label: "Tenants", Icon: Building2 },
  { id: "routers", label: "Routeurs", Icon: RouterIcon },
  { id: "payments", label: "Paiements & webhooks", Icon: CreditCard },
  { id: "ledger", label: "Ledger", Icon: BookOpenText },
  { id: "audit", label: "Audit & sécurité", Icon: ShieldCheck },
  { id: "operations", label: "Opérations", Icon: Activity },
  { id: "users", label: "Comptes utilisateurs", Icon: Users },
];
const labels: Record<Section, string> = { overview: "Vue globale", tenants: "Tenants", routers: "Routeurs", payments: "Paiements & webhooks", ledger: "Ledger", audit: "Audit & sécurité", operations: "Opérations", users: "Comptes utilisateurs" };
const unavailable = <T,>(): ApiResult<T> => ({ state: "unavailable", message: "Chargement des données…" });
const money = (value: number) => `${new Intl.NumberFormat("fr-FR", { maximumFractionDigits: 0 }).format(value)} XOF`;

function StatusPanel({ result, reload, title }: { result: ApiResult<unknown>; reload: () => void; title?: string }) {
  return <Card className="empty-state" role="status" aria-live="polite"><div className="empty-symbol" aria-hidden="true"><RefreshCw size={18}/></div><CardTitle>{title ?? (result.state === "connected" ? "Aucune donnée disponible" : "API globale indisponible")}</CardTitle><CardDescription>{result.state === "connected" ? "Aucun enregistrement n’a été renvoyé pour cette rubrique." : result.message}</CardDescription><Button onClick={reload} type="button">Recharger</Button></Card>;
}

export default function GlobalAdmin() {
  const [section, setSection] = useState<Section>("overview");
  const [sidebarCollapsed, setSidebarCollapsed] = useState(false);
  const [data, setData] = useState<{ tenants: ApiResult<Tenant[]>; routers: ApiResult<Router[]>; payments: ApiResult<Payment[]>; ledger: ApiResult<LedgerEntry[]>; audit: ApiResult<AuditEvent[]>; operations: ApiResult<OperationalStatus[]>; users: ApiResult<AdminUser[]> }>({ tenants: unavailable(), routers: unavailable(), payments: unavailable(), ledger: unavailable(), audit: unavailable(), operations: unavailable(), users: unavailable() });
  const [summary, setSummary] = useState<ApiResult<PlatformSummary>>(unavailable());
  const [health, setHealth] = useState<ApiResult<HealthResponse>>(unavailable());
  const [busy, setBusy] = useState(false);

  const refresh = useCallback(async () => {
    setBusy(true);
    const [health, summary, tenants, routers, payments, ledger, audit, operations, users] = await Promise.all([api.health(), api.summary(), api.tenants(), api.routers(), api.payments(), api.ledger(), api.audit(), api.operations(), api.users()]);
    setHealth(health);
    setSummary(summary);
    setData({ tenants, routers, payments, ledger, audit, operations, users });
    setBusy(false);
  }, []);
  useEffect(() => { const timer = window.setTimeout(() => { void refresh(); }, 0); return () => window.clearTimeout(timer); }, [refresh]);
  const reload = () => { void refresh(); };
  const toggleSidebar = () => setSidebarCollapsed((collapsed) => !collapsed);
  const serviceReachable = health.state === "connected" && health.data.status === "ok";

  return <div className="admin-shell">
    <aside className={`side ${sidebarCollapsed ? "is-collapsed" : ""}`}>
      <Button className="side-toggle" variant="outline" size="icon" type="button" onClick={toggleSidebar} aria-label={sidebarCollapsed ? "Déployer la barre latérale" : "Rétracter la barre latérale"} aria-expanded={!sidebarCollapsed} aria-controls="admin-navigation" title={sidebarCollapsed ? "Déployer la barre latérale" : "Rétracter la barre latérale"}>
        {sidebarCollapsed ? <ChevronRight size={16} aria-hidden /> : <ChevronLeft size={16} aria-hidden />}
      </Button>
      <a className="brand" href="#top"><span className="mark">M</span><span className="brand-copy">miksprit<small>PLATFORM ADMIN</small></span></a>
      <div className="scope-row"><span className="scope-label">Contrôle global</span></div>
      <nav id="admin-navigation" aria-label="Navigation administrateur">{sections.map(({ Icon, ...item }) => <Button variant="ghost" type="button" key={item.id} onClick={() => setSection(item.id)} className={`nav-link ${section === item.id ? "selected" : ""}`} aria-current={section === item.id ? "page" : undefined} aria-label={item.label} title={sidebarCollapsed ? item.label : undefined}><Icon size={18} aria-hidden="true"/><span className="nav-label">{item.label}</span><ChevronRight className="nav-chevron" size={15} aria-hidden="true"/></Button>)}</nav>
      <Separator className="side-separator"/><div className="side-bottom"><p>Accès réservé aux administrateurs plateforme authentifiés.</p><div className="operator"><span className="operator-avatar">SA</span><span className="operator-copy"><strong>Super-admin</strong><small>Session requise</small></span></div></div>
    </aside>
    <main className={`work-area ${sidebarCollapsed ? "is-collapsed" : ""}`} id="top"><header className="top"><div><span>Administration centrale</span><b>/</b><strong>{labels[section]}</strong></div><div className="top-right"><span className={`api-state ${serviceReachable ? "up" : "down"}`} role="status" aria-live="polite"><i/>{serviceReachable ? `Service joignable · ${health.data.service}` : "Service API indisponible"}</span><Button variant="outline" size="sm" onClick={reload} disabled={busy}><RefreshCw size={14}/>{busy ? "Actualisation…" : "Actualiser"}</Button></div></header>
      <section className="page-content"><div className="heading"><div><p className="kicker">MIKSPRIT · ADMINISTRATION GLOBALE</p><h1>{labels[section]}</h1><p>Administration de la plateforme, opérateurs et ressources multi-tenant.</p></div><UiBadge>Accès super-admin</UiBadge></div>
        {section === "overview" && <Overview result={summary} reload={reload}/>}
        {section === "tenants" && <List result={data.tenants} reload={reload} title="Tenants" render={(item) => <tr key={item.id}><td><strong>{item.name}</strong></td><td>{item.slug}</td><td>{item.createdAt}</td><td><UiBadge>{item.status}</UiBadge></td></tr>} columns={["Organisation", "Identifiant", "Création", "État"]}/>}
        {section === "routers" && <List result={data.routers} reload={reload} title="Routeurs plateforme" render={(item) => <tr key={item.id}><td><strong>{item.name}</strong></td><td>{item.tenantName}</td><td>{item.siteName}</td><td>{item.version ?? "—"}</td><td><UiBadge>{item.status}</UiBadge></td></tr>} columns={["Routeur", "Tenant", "Site", "Version", "État"]}/>}
        {section === "payments" && <List result={data.payments} reload={reload} title="Paiements & webhooks" render={(item) => <tr key={item.id}><td><strong>{item.reference}</strong></td><td>{item.tenantName}</td><td>{money(item.amountXof)} {item.currency}</td><td>{item.createdAt}</td><td><UiBadge>{item.status}</UiBadge></td></tr>} columns={["Référence", "Tenant", "Montant", "Date", "État"]}/>}
        {section === "ledger" && <List result={data.ledger} reload={reload} title="Ledger plateforme" render={(item) => <tr key={item.id}><td>{item.createdAt}</td><td><strong>{item.tenantName}</strong></td><td>{item.entryType}</td><td>{money(item.amountXof)} {item.currency}</td></tr>} columns={["Date", "Tenant", "Écriture", "Montant"]}/>}
        {section === "audit" && <List result={data.audit} reload={reload} title="Journal d’audit" render={(item) => <tr key={item.id}><td>{item.createdAt}</td><td><strong>{item.actor}</strong></td><td>{item.action}</td><td>{item.target}</td></tr>} columns={["Date", "Acteur", "Action", "Cible"]}/>}
        {section === "operations" && <List result={data.operations} reload={reload} title="Opérations & supervision" render={(item) => <tr key={item.name}><td><strong>{item.name}</strong></td><td><UiBadge>{item.state}</UiBadge></td><td>{item.observedAt}</td></tr>} columns={["Composant", "État", "Observé le"]}/>}
        {section === "users" && <UsersPanel result={data.users} reload={reload}/>}
        <footer className="foot"><span>Les informations sensibles des routeurs et PSP ne sont jamais affichées ici.</span><span>Administration globale · données depuis l’API</span></footer>
      </section>
    </main>
  </div>;
}

function UsersPanel({ result, reload }: { result: ApiResult<AdminUser[]>; reload: () => void }) {
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState("");

  async function invite(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); setBusy(true); setMessage("");
    const element = event.currentTarget;
    const form = new FormData(element);
    try {
      await api.inviteOwner({ email: String(form.get("email")), organizationName: String(form.get("organizationName")), slug: String(form.get("slug")) });
      element.reset(); setMessage("Invitation envoyée au propriétaire."); reload();
    } catch (error) { setMessage(error instanceof Error ? error.message : "L’invitation a échoué."); }
    finally { setBusy(false); }
  }

  async function setActive(user: AdminUser, active: boolean) {
    setBusy(true); setMessage("");
    try { await api.setUserActive(user.id, active); setMessage(active ? "Compte activé." : "Compte désactivé."); reload(); }
    catch (error) { setMessage(error instanceof Error ? error.message : "La mise à jour a échoué."); }
    finally { setBusy(false); }
  }

  async function resend(user: AdminUser) {
    setBusy(true); setMessage("");
    try { await api.resendInvitation(user.id); setMessage("Nouvelle invitation envoyée."); }
    catch (error) { setMessage(error instanceof Error ? error.message : "L’envoi a échoué."); }
    finally { setBusy(false); }
  }

  return <div className="users-layout"><Card className="table-card"><CardHeader className="table-title"><div><CardTitle>Inviter un propriétaire</CardTitle><CardDescription>Un lien à usage unique lui permettra de définir son mot de passe.</CardDescription></div></CardHeader><CardContent><form className="admin-invite-form" onSubmit={invite}><label htmlFor="owner-email">Adresse e-mail</label><input id="owner-email" name="email" type="email" autoComplete="email" required maxLength={190}/><label htmlFor="organization-name">Nom de l’organisation</label><input id="organization-name" name="organizationName" required maxLength={160}/><label htmlFor="organization-slug">Identifiant URL</label><input id="organization-slug" name="slug" required maxLength={80} pattern={ORGANIZATION_SLUG_PATTERN}/><Button type="submit" disabled={busy}>{busy ? "Traitement…" : "Créer et inviter"}</Button><p className="form-message" role="status" aria-live="polite">{message}</p></form></CardContent></Card>
    {result.state !== "connected" || result.data.length === 0 ? <StatusPanel result={result} reload={reload} title={result.state === "connected" ? "Aucun compte enregistré" : "Comptes indisponibles"}/> : <Card className="table-card"><CardHeader className="table-title"><div><CardTitle>Comptes de la plateforme</CardTitle><CardDescription>Activation, désactivation et renvoi d’invitation.</CardDescription></div><Button variant="secondary" size="sm" onClick={reload}>Recharger</Button></CardHeader><CardContent className="table-scroll"><table><thead><tr><th>Adresse e-mail</th><th>Rôle</th><th>État</th><th>Actions</th></tr></thead><tbody>{result.data.map((user) => <tr key={user.id}><td><strong>{user.email}</strong></td><td>{user.roles.join(", ")}</td><td><UiBadge>{user.invitationPending ? "Invitation en attente" : user.active ? "Actif" : "Désactivé"}</UiBadge></td><td className="user-actions"><Button variant="outline" size="sm" disabled={busy} onClick={() => void setActive(user, !user.active)}>{user.active ? "Désactiver" : "Activer"}</Button>{user.invitationPending && <Button variant="secondary" size="sm" disabled={busy} onClick={() => void resend(user)}>Renvoyer l’invitation</Button>}</td></tr>)}</tbody></table></CardContent></Card>}</div>;
}

function Overview({ result, reload }: { result: ApiResult<PlatformSummary>; reload: () => void }) {
  if (result.state !== "connected") return <StatusPanel result={result} reload={reload} title="Vue globale indisponible"/>;
  const metrics = [
    { label: "Organisations actives", value: result.data.tenants.toLocaleString("fr-FR") },
    { label: "Comptes actifs", value: result.data.activeUsers.toLocaleString("fr-FR") },
    { label: "Routeurs actifs", value: result.data.activeRouters.toLocaleString("fr-FR") },
    { label: "Paiements du jour", value: money(result.data.paymentsTodayXof) },
    { label: "Éléments outbox en attente", value: result.data.pendingOutbox.toLocaleString("fr-FR") },
  ];
  return <div className="overview-metrics">{metrics.map((metric) => <Card className="metric-card" key={metric.label}><CardDescription>{metric.label}</CardDescription><CardTitle>{metric.value}</CardTitle></Card>)}</div>;
}

function List<T>({ result, reload, title, columns, render }: { result: ApiResult<T[]>; reload: () => void; title: string; columns: string[]; render: (item: T) => ReactNode }) {
  if (result.state !== "connected" || result.data.length === 0) return <StatusPanel result={result} reload={reload} title={result.state === "connected" ? `Aucun ${title.toLowerCase()} renvoyé` : `${title} indisponibles`}/>;
  return <Card className="table-card"><CardHeader className="table-title"><div><CardTitle>{title}</CardTitle><CardDescription>Enregistrements renvoyés par les endpoints d’administration.</CardDescription></div><Button variant="secondary" size="sm" onClick={reload}>Recharger</Button></CardHeader><CardContent className="table-scroll"><table><thead><tr>{columns.map((column) => <th key={column}>{column}</th>)}</tr></thead><tbody>{result.data.map(render)}</tbody></table></CardContent></Card>;
}
