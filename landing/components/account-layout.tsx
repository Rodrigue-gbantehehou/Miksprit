"use client";

import Link from "next/link";
import type { ReactNode } from "react";

export function AccountLayout({ action, children, headline = "Votre Wi-Fi, prêt à vendre.", fullScreen = false }: { action?: ReactNode; children: ReactNode; headline?: string; fullScreen?: boolean }) {
  return (
    <div className={`account-shell${fullScreen ? " account-shell-fullscreen" : ""}`}>
      {!fullScreen && <header className="account-header wrap">
        <Link className="brand" href="/" aria-label="Miksprit, accueil">
          <span className="brand-mark" aria-hidden="true">M</span>
          <span>miksprit</span>
        </Link>
        <nav aria-label="Navigation du compte">{action}</nav>
      </header>}
      <main className={`account-main${fullScreen ? " account-main-fullscreen" : ""}`}>
        <div className="account-layout-inner">
          <aside className="account-aside" aria-label="Gestion Wi-Fi Miksprit">
            <img src="/images/auth-owner-portrait.jpg" alt="" />
            <div className="account-aside-copy"><strong>{headline}</strong><span>Vos tickets Wi-Fi, vendus en ligne.</span></div>
          </aside>
          <section className="account-content">{children}</section>
        </div>
      </main>
      {!fullScreen && <footer className="account-footer wrap"><span>© 2026 Miksprit</span><span>Gestion des accès Wi-Fi</span></footer>}
    </div>
  );
}
