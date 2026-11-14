"use client";

import Link from "next/link";
import { FormEvent, useState } from "react";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";

type Props = { mode: "request" | "reset"; invitation?: boolean };
const apiBase = (process.env.NEXT_PUBLIC_API_BASE ?? "/api/v1").replace(/\/$/, "");

async function csrfToken(): Promise<string> {
  const response = await fetch(`${apiBase}/auth/csrf`, { credentials: "same-origin", cache: "no-store", headers: { Accept: "application/json" } });
  if (!response.ok) throw new Error("Le service est momentanément indisponible.");
  const result = await response.json() as { csrfToken?: string };
  if (!result.csrfToken) throw new Error("Le service est momentanément indisponible.");
  return result.csrfToken;
}

export function AccountLinkForm({ mode, invitation = false }: Props) {
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState("");
  const [success, setSuccess] = useState(false);
  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); setBusy(true); setMessage(""); setSuccess(false);
    const form = new FormData(event.currentTarget);
    try {
      const csrf = await csrfToken();
      if (mode === "request") {
        const response = await fetch(`${apiBase}/auth/password/request`, { method: "POST", credentials: "same-origin", cache: "no-store", headers: { Accept: "application/json", "Content-Type": "application/json" }, body: JSON.stringify({ email: form.get("email"), _csrf_token: csrf }) });
        if (response.status === 429) throw new Error("Trop de demandes. Attendez avant de réessayer.");
        if (!response.ok) throw new Error("La demande n’a pas pu être traitée. Réessayez plus tard.");
        setSuccess(true); setMessage("Si cette adresse correspond à un compte, un lien sécurisé vient d’être envoyé.");
      } else {
        const params = new URLSearchParams(window.location.search);
        const password = String(form.get("password") ?? "");
        if (password !== String(form.get("confirmation") ?? "")) throw new Error("Les deux mots de passe ne correspondent pas.");
        const response = await fetch(`${apiBase}/auth/password/reset`, { method: "POST", credentials: "same-origin", cache: "no-store", headers: { Accept: "application/json", "Content-Type": "application/json" }, body: JSON.stringify({ token: params.get("token"), purpose: params.get("purpose"), password, _csrf_token: csrf }) });
        const result = await response.json().catch(() => ({})) as { message?: string; error?: string };
        if (!response.ok) throw new Error(result.error ?? "Le lien est invalide ou expiré. Demandez-en un nouveau.");
        setSuccess(true); setMessage(result.message ?? "Mot de passe mis à jour.");
      }
    } catch (error) { setMessage(error instanceof Error ? error.message : "Le service est momentanément indisponible."); }
    finally { setBusy(false); }
  }

  const title = mode === "request" ? "Réinitialiser le mot de passe" : invitation ? "Créer votre accès" : "Choisir un nouveau mot de passe";
  const description = mode === "request" ? "Saisissez l’adresse de votre compte. Si elle est reconnue, nous vous enverrons un lien." : "Choisissez un mot de passe d’au moins 12 caractères.";

  return <main className="login-shell"><header className="header wrap"><Link className="brand" href="/"><span className="brand-mark">M</span>miksprit</Link><Link className="text-link" href="/connexion">Connexion</Link></header><section className="login-layout wrap"><div className="login-intro"><p className="eyebrow">ESPACE SÉCURISÉ</p><h1>{mode === "request" ? <>Retrouvez votre<br/><em>accès.</em></> : <>Sécurisez votre<br/><em>compte.</em></>}</h1><p>Les liens sont à usage unique et expirent automatiquement.</p></div><Card className="login-card"><CardHeader className="login-card-head"><span className="eyebrow">COMPTE MIKSPRIT</span><CardTitle>{title}</CardTitle><CardDescription>{description}</CardDescription></CardHeader><CardContent><form onSubmit={submit} className="account-link-form">{mode === "request" ? <><label htmlFor="email">Adresse e-mail</label><input autoComplete="email" id="email" name="email" type="email" required maxLength={190} placeholder="nom@entreprise.com" /></> : <><label htmlFor="password">Nouveau mot de passe</label><input autoComplete="new-password" id="password" name="password" type="password" required minLength={12} maxLength={4096} /><label htmlFor="confirmation">Confirmer le mot de passe</label><input autoComplete="new-password" id="confirmation" name="confirmation" type="password" required minLength={12} maxLength={4096} /></>}<Button className="login-submit" type="submit" disabled={busy || success}>{busy ? "Traitement…" : mode === "request" ? "Envoyer le lien" : "Enregistrer le mot de passe"}</Button><p className={success ? "form-message account-success" : "form-message"} role="status" aria-live="polite">{message}</p></form><div className="login-card-foot"><Link href="/connexion">Retour à la connexion</Link></div></CardContent></Card></section><footer className="footer wrap"><Link className="brand" href="/"><span className="brand-mark">M</span>miksprit</Link><span>© 2026 Miksprit · Connexion protégée</span></footer></main>;
}
