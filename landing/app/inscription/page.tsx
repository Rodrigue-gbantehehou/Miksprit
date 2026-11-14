"use client";

import Link from "next/link";
import { useState } from "react";
import type { FormEvent } from "react";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";

const apiBase = (process.env.NEXT_PUBLIC_API_BASE ?? "/api/v1").replace(/\/$/, "");
const slugPattern = "[a-z0-9]([a-z0-9\\x2d]{0,78}[a-z0-9])?";

export default function RegistrationPage() {
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState("");
  const [success, setSuccess] = useState(false);
  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); setBusy(true); setMessage(""); setSuccess(false);
    const formElement = event.currentTarget;
    const form = new FormData(formElement);
    try {
      const csrfResponse = await fetch(apiBase + "/auth/csrf", { credentials: "same-origin", cache: "no-store" });
      if (!csrfResponse.ok) throw new Error("Le service est momentanément indisponible.");
      const csrf = await csrfResponse.json() as { csrfToken: string };
      const response = await fetch(apiBase + "/public/operator-registrations", { method: "POST", credentials: "same-origin", cache: "no-store", headers: { "Content-Type": "application/json", Accept: "application/json" }, body: JSON.stringify({ email: form.get("email"), organizationName: form.get("organizationName"), organizationSlug: form.get("organizationSlug"), password: form.get("password"), _csrf_token: csrf.csrfToken }) });
      const result = await response.json().catch(() => ({})) as { message?: string };
      if (response.status === 429) {
        const retryAfter = Number(response.headers.get("Retry-After") ?? 0);
        const minutes = Math.ceil(retryAfter / 60);
        throw new Error(minutes > 0 ? `Trop de demandes. Réessayez dans environ ${minutes} min.` : "Trop de demandes. Réessayez dans quelques minutes.");
      }
      if (!response.ok) throw new Error(response.status === 409 ? "Cette adresse e-mail ou cet identifiant d’espace est déjà utilisé." : response.status === 422 ? "Vérifiez les informations saisies : le nom, l’identifiant ou le mot de passe n’est pas valide." : response.status >= 500 ? "Le service d’inscription est temporairement indisponible. Réessayez dans quelques minutes." : "Vérifiez les informations et réessayez.");
      setSuccess(true);
      setMessage(result.message ?? "Votre compte et votre espace propriétaire sont créés. Vous pouvez vous connecter.");
      formElement.reset();
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
            <div><span>01</span><p><strong>Un portail pour vos clients</strong><small>Vos clients choisissent leur forfait sans compte Miksprit.</small></p></div>
            <div><span>02</span><p><strong>Une console pour votre activité</strong><small>Gérez vos sites, vos routeurs et vos ventes au même endroit.</small></p></div>
            <div><span>03</span><p><strong>Un espace prêt dès l’inscription</strong><small>Créez votre compte et commencez à configurer votre activité.</small></p></div>
          </div>
        </div>
        <Card className="login-card signup-card">
          <CardHeader className="login-card-head"><span className="eyebrow">CRÉATION DE COMPTE</span><CardTitle>Créer mon espace opérateur</CardTitle><CardDescription>Votre espace propriétaire est créé dès que vous validez ce formulaire.</CardDescription></CardHeader>
          <CardContent><form onSubmit={submit}>
            {message && <p className={`form-message signup-feedback${success ? " is-success" : " is-error"}`} role="status" aria-live="polite">{message}{success && <> <Link href="/connexion">Se connecter</Link></>}</p>}
            <label htmlFor="organizationName">Nom de votre activité</label><input id="organizationName" name="organizationName" autoComplete="organization" required maxLength={160} placeholder="Ex. Wi-Fi du Centre"/>
            <label htmlFor="organizationSlug">Identifiant de l’espace</label><input id="organizationSlug" name="organizationSlug" required maxLength={80} pattern={slugPattern} placeholder="mon-reseau"/><small className="signup-field-help">Lettres minuscules, chiffres et tirets.</small>
            <label htmlFor="email">Adresse e-mail</label><input id="email" name="email" type="email" autoComplete="email" required maxLength={190} placeholder="vous@exemple.com"/>
            <label htmlFor="password">Mot de passe</label><input id="password" name="password" type="password" autoComplete="new-password" minLength={12} maxLength={4096} required placeholder="12 caractères minimum"/>
            <Button className="login-submit" type="submit" disabled={busy}>{busy ? "Envoi…" : "Créer mon compte"}<span aria-hidden>↗</span></Button>
            <p className="signup-consent">Votre mot de passe est protégé. Aucune vérification par e-mail n’est nécessaire pour créer votre espace.</p>
          </form></CardContent>
        </Card>
      </section>
      <footer className="footer wrap"><Link className="brand" href="/"><span className="brand-mark">M</span>miksprit</Link><span>© 2026 Miksprit</span><Link href="/">Découvrir Miksprit</Link></footer>
    </main>
  );
}
