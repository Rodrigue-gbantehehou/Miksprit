"use client";

import Link from "next/link";
import { ArrowRight, ArrowUpRight, Menu, X } from "lucide-react";
import { marketingSectionLinks } from "@/components/marketing-navigation";
import { useCallback, useEffect, useRef, useState } from "react";

export function MobileMarketingMenu() {
  const [open, setOpen] = useState(false);
  const drawerRef = useRef<HTMLElement>(null);
  const triggerRef = useRef<HTMLButtonElement>(null);
  const close = useCallback(() => setOpen(false), []);

  useEffect(() => {
    if (!open) return;

    const previousOverflow = document.body.style.overflow;
    const trigger = triggerRef.current;
    document.body.style.overflow = "hidden";
    window.requestAnimationFrame(() => drawerRef.current?.querySelector<HTMLElement>("a")?.focus());

    function onKeyDown(event: KeyboardEvent) {
      if (event.key === "Escape") {
        close();
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

    function onResize() {
      if (window.matchMedia("(min-width: 681px)").matches) close();
    }

    window.addEventListener("keydown", onKeyDown);
    window.addEventListener("resize", onResize);
    return () => {
      document.body.style.overflow = previousOverflow;
      window.removeEventListener("keydown", onKeyDown);
      window.removeEventListener("resize", onResize);
      if (trigger?.getClientRects().length) trigger.focus();
    };
  }, [open, close]);

  return <>
    <button
      ref={triggerRef}
      className="marketing-menu-trigger"
      type="button"
      aria-label="Ouvrir le menu mobile"
      aria-expanded={open}
      aria-controls="mobile-marketing-navigation"
      onClick={() => setOpen(true)}
    >
      <Menu size={21} aria-hidden="true" />
    </button>
    <aside
      ref={drawerRef}
      id="mobile-marketing-navigation"
      className={`marketing-mobile-drawer ${open ? "is-open" : ""}`}
      role={open ? "dialog" : undefined}
      aria-modal={open || undefined}
      aria-label={open ? "Navigation Miksprit" : undefined}
      aria-hidden={!open}
      inert={!open}
    >
      <div className="marketing-mobile-heading">
        <Link className="brand" href="/" onClick={close} aria-label="Miksprit, accueil"><span className="brand-mark">M</span><span>miksprit</span></Link>
        <button className="marketing-menu-close" type="button" onClick={close} aria-label="Fermer le menu"><X size={20} aria-hidden="true" /></button>
      </div>
      <p className="marketing-mobile-kicker">ESPACE OPÉRATEUR WI-FI</p>
      <nav aria-label="Pages de Miksprit">
        {marketingSectionLinks.map((link, index) => <a href={link.href} key={link.href} onClick={close}><span>{String(index + 1).padStart(2, "0")}</span><strong>{link.label}</strong><ArrowUpRight size={19} aria-hidden="true" /></a>)}
      </nav>
      <div className="marketing-mobile-footer">
        <Link className="marketing-mobile-login" href="/connexion" onClick={close}>Déjà inscrit ? <strong>Connexion</strong></Link>
        <Link className="marketing-mobile-cta" href="/inscription" onClick={close}>Créer un compte <ArrowRight size={17} aria-hidden="true" /></Link>
      </div>
    </aside>
  </>;
}
