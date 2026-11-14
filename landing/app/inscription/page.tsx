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
  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); setBusy(true); setMessage("");
    const form = new FormData(event.currentTarget);
    try {
      const csrfResponse = await fetch(apiBase + "/auth/csrf", { credentials: "same-origin", cache: "no-store" });
      if (!csrfResponse.ok) throw new Error("Le service est momentanément indisponible.");
      const csrf = await csrfResponse.json() as { csrfToken: string };
      const response = await fetch(apiBase + "/public/operator-registrations", { method: "POST", credentials: "same-origin", cache: "no-store", headers: { "Content-Type": "application/json", Accept: "application/json" }, body: JSON.stringify({ email: form.get("email"), organizationName: form.get("organizationName"), organizationSlug: form.get("organizationSlug"), password: form.get("password"), _csrf_token: csrf.csrfToken }) });
      const result = await response.json().catch(() => ({})) as { message?: string };
      if (!response.ok) throw new Error(response.status === 429 ? "Trop de demandes. Réessayez plus tard." : "Vérifiez les informations et réessayez.");
      setMessage(result.message ?? "Si les informations peuvent être utilisées, un lien de vérification sera envoyé.");
      event.currentTarget.reset();
    } catch (error) { setMessage(error instanceof Error ? error.message : "Inscription indisponible."); }
    finally { setBusy(false); }
  }
  return <main className="login-shell"><header className="header wrap"><Link className="brand" href="/"><span className="brand-mark">M</span>miksprit</Link><Link className="text-link" href="/connexion">Se connecter</Link></header><section className="login-layout wrap"><div className="login-intro"><p className="eyebrow">ESPACE OPÉRATEUR</p><h1>Votre réseau,<br/><em>votre espace.</em></h1><p>Créez votre compte opérateur. Après vérification de votre adresse, votre organisation et votre espace propriétaire seront créés.</p></div><Card className="login-card"><CardHeader className="login-card-head"><span className="eyebrow">INSCRIPTION GRATUITE</span><CardTitle>Créer un espace opérateur</CardTitle><CardDescription>Un lien de vérification sera envoyé par courriel.</CardDescription></CardHeader><CardContent><form onSubmit={submit}><label htmlFor="organizationName">Nom de votre activité</label><input id="organizationName" name="organizationName" autoComplete="organization" required maxLength={160}/><label htmlFor="organizationSlug">Identifiant de l’espace</label><input id="organizationSlug" name="organizationSlug" required maxLength={80} pattern={slugPattern} placeholder="mon-reseau"/><label htmlFor="email">Adresse e-mail</label><input id="email" name="email" type="email" autoComplete="email" required maxLength={190}/><label htmlFor="password">Mot de passe</label><input id="password" name="password" type="password" autoComplete="new-password" minLength={12} maxLength={4096} required/><Button className="login-submit" type="submit" disabled={busy}>{busy ? "Envoi…" : "Créer mon espace"}<span aria-hidden>↗</span></Button><p className="form-message" role="status" aria-live="polite">{message}</p></form></CardContent></Card></section><footer className="footer wrap"><Link className="brand" href="/"><span className="brand-mark">M</span>miksprit</Link><span>© 2026 Miksprit</span></footer></main>;
}
