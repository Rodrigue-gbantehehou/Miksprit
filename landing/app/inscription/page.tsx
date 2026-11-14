"use client";

import Link from "next/link";
import { Check, ShieldCheck, Wifi } from "lucide-react";
import { useState } from "react";
import type { FormEvent } from "react";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";

const apiBase = (process.env.NEXT_PUBLIC_API_BASE ?? "/api/v1").replace(/\/$/, "");
const slugPattern = "[a-z0-9]([a-z0-9\\x2d]{0,78}[a-z0-9])?";

export default function RegistrationPage() {
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState("");
  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); setBusy(true); setMessage("");
    const form = new FormData(event.currentTarget);
    try {
      const csrfResponse = await fetch(apiBase + "/auth/csrf", { credentials: "same-origin", cache: "no-store" });
      if (!csrfResponse.ok) throw new Error("Le service est momentanément indisponible.");
      const csrf = await csrfResponse.json() as { csrfToken: string };
      const response = await fetch(apiBase + "/public/operator-registrations", { method: "POST", credentials: "same-origin", cache: "no-store", headers: { "Content-Type": "application/json", Accept: "application/json" }, body: JSON.stringify({ email: form.get("email"), organizationName: form.get("organizationName"), organizationSlug: form.get("organizationSlug"), password: form.get("password"), _csrf_token: csrf.csrfToken }) });
      const result = await response.json().catch(() => ({})) as { message?: string };
      if (!response.ok) throw new Error(response.status === 429 ? "Trop de demandes. Réessayez plus tard." : response.status >= 500 ? "Le service d’inscription est temporairement indisponible. Réessayez dans quelques minutes." : "Vérifiez les informations et réessayez.");
      setMessage(result.message ?? "Si les informations peuvent être utilisées, un lien de vérification sera envoyé.");
      event.currentTarget.reset();
    } catch (error) { setMessage(error instanceof Error ? error.message : "Inscription indisponible."); }
    finally { setBusy(false); }
  }
  return (
    <main className="login-shell signup-shell">
      <header className="header wrap signup-header">
        <Link className="brand" href="/" aria-label="Miksprit, accueil"><span className="brand-mark">M</span>miksprit</Link>
        <Link className="text-link" href="/connexion">Déjà un compte ? Se connecter</Link>
      </header>
      <section className="login-layout wrap signup-layout">
        <div className="login-intro signup-intro">
          <p className="eyebrow">ESPACE OPÉRATEUR WI-FI</p>
          <h1>Votre réseau,<br/><em>mieux géré.</em></h1>
          <p>Ouvrez votre espace Miksprit pour organiser vos sites, vos forfaits et votre activité Wi-Fi.</p>
          <div className="signup-benefits">
            <div><span><Wifi size={17}/></span><p><strong>Un portail pour vos clients</strong><small>Vos clients choisissent leur forfait sans compte Miksprit.</small></p></div>
            <div><span><Check size={17}/></span><p><strong>Une console pour votre activité</strong><small>Gérez vos sites, vos routeurs et vos ventes au même endroit.</small></p></div>
            <div><span><ShieldCheck size={17}/></span><p><strong>Une adresse vérifiée</strong><small>Confirmez votre e-mail pour activer votre espace propriétaire.</small></p></div>
          </div>
        </div>
        <Card className="login-card signup-card">
          <CardHeader className="login-card-head"><span className="eyebrow">CRÉATION DE COMPTE</span><CardTitle>Créer mon espace opérateur</CardTitle><CardDescription>Renseignez votre activité. Nous vous enverrons un lien pour confirmer votre adresse e-mail.</CardDescription></CardHeader>
          <CardContent><form onSubmit={submit}>
            <label htmlFor="organizationName">Nom de votre activité</label><input id="organizationName" name="organizationName" autoComplete="organization" required maxLength={160} placeholder="Ex. Wi-Fi du Centre"/>
            <label htmlFor="organizationSlug">Identifiant de l’espace</label><input id="organizationSlug" name="organizationSlug" required maxLength={80} pattern={slugPattern} placeholder="mon-reseau"/><small className="signup-field-help">Lettres minuscules, chiffres et tirets.</small>
            <label htmlFor="email">Adresse e-mail</label><input id="email" name="email" type="email" autoComplete="email" required maxLength={190} placeholder="vous@exemple.com"/>
            <label htmlFor="password">Mot de passe</label><input id="password" name="password" type="password" autoComplete="new-password" minLength={12} maxLength={4096} required placeholder="12 caractères minimum"/>
            <Button className="login-submit" type="submit" disabled={busy}>{busy ? "Envoi…" : "Créer mon compte"}<span aria-hidden>↗</span></Button>
            <p className="signup-consent"><ShieldCheck size={14}/> Votre mot de passe est protégé. L’espace est créé après vérification de l’e-mail.</p>
            <p className="form-message" role="status" aria-live="polite">{message}</p>
          </form></CardContent>
        </Card>
      </section>
      <footer className="footer wrap"><Link className="brand" href="/"><span className="brand-mark">M</span>miksprit</Link><span>© 2026 Miksprit</span><Link href="/">Découvrir Miksprit</Link></footer>
    </main>
  );
}
