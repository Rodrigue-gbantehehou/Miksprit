"use client";

import Link from "next/link";
import { FormEvent, useState } from "react";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { AccountLayout } from "@/components/account-layout";

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
        if (!response.ok) throw new Error("Demande impossible. Réessayez plus tard.");
        setSuccess(true); setMessage("Si un compte correspond, un lien sécurisé a été envoyé.");
      } else {
        const params = new URLSearchParams(window.location.search);
        const password = String(form.get("password") ?? "");
        if (password !== String(form.get("confirmation") ?? "")) throw new Error("Les deux mots de passe ne correspondent pas.");
        const response = await fetch(`${apiBase}/auth/password/reset`, { method: "POST", credentials: "same-origin", cache: "no-store", headers: { Accept: "application/json", "Content-Type": "application/json" }, body: JSON.stringify({ token: params.get("token"), purpose: params.get("purpose"), password, _csrf_token: csrf }) });
        const result = await response.json().catch(() => ({})) as { message?: string; error?: string };
        if (!response.ok) throw new Error(result.error ?? "Lien invalide ou expiré. Demandez-en un nouveau.");
        setSuccess(true); setMessage(result.message ?? "Mot de passe mis à jour.");
      }
    } catch (error) { setMessage(error instanceof Error ? error.message : "Service momentanément indisponible."); }
    finally { setBusy(false); }
  }

  const title = mode === "request" ? "Réinitialiser le mot de passe" : invitation ? "Créer votre accès" : "Choisir un nouveau mot de passe";
  const description = mode === "request" ? "Nous enverrons un lien si l’adresse correspond à un compte." : "12 caractères minimum.";

  return <AccountLayout action={<Link className="account-nav-link" href="/connexion">Connexion</Link>}>
    <h1 className="account-title">{title}</h1>
    <p className="account-description">{description}</p>
    <Card className="login-card account-form-card"><CardContent>
      <form onSubmit={submit} className="account-link-form">
        {mode === "request" ? <>
          <label htmlFor="email">Adresse e-mail</label>
          <input autoComplete="email" id="email" name="email" type="email" required maxLength={190} placeholder="vous@exemple.com" />
        </> : <>
          <label htmlFor="password">Nouveau mot de passe</label>
          <input autoComplete="new-password" id="password" name="password" type="password" required minLength={12} maxLength={4096} />
          <label htmlFor="confirmation">Confirmer le mot de passe</label>
          <input autoComplete="new-password" id="confirmation" name="confirmation" type="password" required minLength={12} maxLength={4096} />
          <p className="field-hint">12 caractères minimum</p>
        </>}
        <Button className="login-submit" type="submit" disabled={busy || success}>{busy ? "Traitement…" : mode === "request" ? "Envoyer le lien" : "Enregistrer"}</Button>
        {message && <p className={success ? "form-message account-success" : "form-message"} role="status" aria-live="polite">{message}</p>}
      </form>
    </CardContent></Card>
  </AccountLayout>;
}
