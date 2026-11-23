import { Activity, BookOpenText, Building2, ChevronLeft, ChevronRight, CreditCard, LayoutDashboard, Menu, Router as RouterIcon, ShieldCheck, Users, X } from "lucide-react";
import type { Ref } from "react";
import { Button } from "@/components/ui/button";
import { Separator } from "@/components/ui/separator";

export type AdminSection = "overview" | "tenants" | "routers" | "payments" | "ledger" | "audit" | "operations" | "users" | "subscriptions";

export const adminSections: { id: AdminSection; label: string; Icon: typeof LayoutDashboard }[] = [
  { id: "overview", label: "Vue d’ensemble", Icon: LayoutDashboard },
  { id: "tenants", label: "Organisations", Icon: Building2 },
  { id: "routers", label: "Routeurs", Icon: RouterIcon },
  { id: "payments", label: "Paiements", Icon: CreditCard },
  { id: "ledger", label: "Journal financier", Icon: BookOpenText },
  { id: "audit", label: "Journal de sécurité", Icon: ShieldCheck },
  { id: "operations", label: "Suivi des services", Icon: Activity },
  { id: "users", label: "Comptes administrateurs", Icon: Users },
  { id: "subscriptions", label: "Abonnements", Icon: CreditCard },
];

export function AdminSidebar({ section, collapsed, onSelect, onToggle }: {
  section: AdminSection;
  collapsed: boolean;
  onSelect: (section: AdminSection) => void;
  onToggle: () => void;
}) {
  return <aside className={`side ${collapsed ? "is-collapsed" : ""}`}>
    <Button className="side-toggle" variant="outline" size="icon" type="button" onClick={onToggle} aria-label={collapsed ? "Déployer la barre latérale" : "Rétracter la barre latérale"} aria-expanded={!collapsed} aria-controls="admin-navigation" title={collapsed ? "Déployer la barre latérale" : "Rétracter la barre latérale"}>
      {collapsed ? <ChevronRight size={16} aria-hidden /> : <ChevronLeft size={16} aria-hidden />}
    </Button>
    <a className="brand" href="#top"><span className="mark">M</span><span className="brand-copy">miksprit<small>ADMINISTRATION</small></span></a>
    <div className="scope-row"><span className="scope-label">Plateforme</span></div>
    <nav id="admin-navigation" aria-label="Navigation administrateur">{adminSections.map(({ Icon, ...item }) => <Button variant="ghost" type="button" key={item.id} onClick={() => onSelect(item.id)} className={`nav-link ${section === item.id ? "selected" : ""}`} aria-current={section === item.id ? "page" : undefined} aria-label={item.label} title={collapsed ? item.label : undefined}><Icon size={18} aria-hidden="true"/><span className="nav-label">{item.label}</span><ChevronRight className="nav-chevron" size={15} aria-hidden="true"/></Button>)}</nav>
    <Separator className="side-separator"/><div className="side-bottom"><div className="operator"><span className="operator-copy"><strong>Administration</strong><small>Gestion plateforme</small></span></div></div>
  </aside>;
}

export function AdminMobileMenuTrigger({ open, onOpen, buttonRef }: { open: boolean; onOpen: () => void; buttonRef?: Ref<HTMLButtonElement> }) {
  return <Button ref={buttonRef} className="mobile-menu-toggle" variant="outline" size="icon" type="button" aria-label="Ouvrir le menu" aria-expanded={open} aria-controls="admin-mobile-navigation" onClick={onOpen}><Menu size={19} aria-hidden="true"/></Button>;
}

export function AdminMobileMenu({ open, section, onSelect, onClose, menuRef }: {
  open: boolean;
  section: AdminSection;
  onSelect: (section: AdminSection) => void;
  onClose: () => void;
  menuRef: Ref<HTMLElement>;
}) {
  return <>
    <aside ref={menuRef} id="admin-mobile-navigation" className={`mobile-drawer ${open ? "is-open" : ""}`} role={open ? "dialog" : undefined} aria-modal={open || undefined} aria-label={open ? "Navigation administrateur" : undefined} aria-hidden={!open} inert={!open}>
      <div className="mobile-drawer-header"><a className="brand" href="#top" onClick={() => onSelect("overview")}><span className="mark">M</span><span className="brand-copy">miksprit<small>ADMINISTRATION</small></span></a><Button className="mobile-drawer-close" variant="outline" size="icon" type="button" aria-label="Fermer le menu" onClick={onClose}><X size={19} aria-hidden="true"/></Button></div>
      <p className="mobile-drawer-kicker">Administration</p>
      <nav aria-label="Rubriques administrateur">{adminSections.map(({ Icon, ...item }) => <Button variant="ghost" type="button" key={item.id} onClick={() => onSelect(item.id)} className={`mobile-menu-item ${section === item.id ? "selected" : ""}`} aria-current={section === item.id ? "page" : undefined}><Icon size={19} aria-hidden="true"/><span>{item.label}</span><ChevronRight size={16} aria-hidden="true"/></Button>)}</nav>
      <div className="mobile-drawer-profile"><div className="operator"><span className="operator-copy"><strong>Administration</strong><small>Gestion plateforme</small></span></div></div>
    </aside>
    {open && <button className="mobile-nav-backdrop" type="button" tabIndex={-1} aria-label="Fermer le menu" onClick={onClose}/>}
  </>;
}
