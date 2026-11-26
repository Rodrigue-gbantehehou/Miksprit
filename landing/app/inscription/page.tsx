"use client";

import Link from "next/link";
import { useState } from "react";
import type { FormEvent } from "react";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { AccountLayout } from "@/components/account-layout";

const apiBase = (process.env.NEXT_PUBLIC_API_BASE ?? "/api/v1").replace(/\/$/, "");

export default function RegistrationPage() {
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState("");
  const [submitted, setSubmitted] = useState(false);
  const [submittedEmail, setSubmittedEmail] = useState("");
  const [resending, setResending] = useState(false);
  const [resendMessage, setResendMessage] = useState("");

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setBusy(true);
    setMessage("");

    const form = new FormData(event.currentTarget);
    const password = String(form.get("password") ?? "");
    if (password !== String(form.get("confirmation") ?? "")) {
      setMessage("Les deux mots de passe ne correspondent pas.");
      setBusy(false);
      return;
    }

    try {
      const csrfResponse = await fetch(apiBase + "/auth/csrf", {
        credentials: "same-origin",
        cache: "no-store",
        headers: { Accept: "application/json" },
      });
      if (!csrfResponse.ok) throw new Error("Le service d’inscription est momentanément indisponible.");
      const csrf = await csrfResponse.json() as { csrfToken?: string };
      if (!csrf.csrfToken) throw new Error("Le service d’inscription est momentanément indisponible.");

      const response = await fetch(apiBase + "/public/operator-registrations", {
        method: "POST",
        credentials: "same-origin",
        cache: "no-store",
        headers: { "Content-Type": "application/json", Accept: "application/json" },
        body: JSON.stringify({
          email: form.get("email"),
          password,
          _csrf_token: csrf.csrfToken,
        }),
      });
      const result = await response.json().catch(() => ({})) as { message?: string };
      if (response.status === 429) throw new Error("Trop de demandes. Attendez avant de réessayer.");
      if (!response.ok) throw new Error("Inscription impossible pour le moment. Réessayez plus tard.");

      setSubmitted(true);
      setSubmittedEmail(String(form.get("email") ?? ""));
      setMessage(result.message ?? "Compte créé. Vous pouvez vous connecter et créer votre espace Miksprit; confirmez votre adresse e-mail en parallèle.");
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "Inscription momentanément indisponible.");
    } finally {
      setBusy(false);
    }
  }

  async function resendVerification() {
    setResending(true);
    setResendMessage("");
    try {
      const csrfResponse = await fetch(apiBase + "/auth/csrf", { credentials: "same-origin", cache: "no-store", headers: { Accept: "application/json" } });
      if (!csrfResponse.ok) throw new Error("Service momentanément indisponible. Réessayez plus tard.");
      const csrf = await csrfResponse.json() as { csrfToken?: string };
      if (!csrf.csrfToken) throw new Error("Service momentanément indisponible. Réessayez plus tard.");
      const response = await fetch(apiBase + "/auth/email/verification/request", {
        method: "POST",
        credentials: "same-origin",
        cache: "no-store",
        headers: { "Content-Type": "application/json", Accept: "application/json" },
        body: JSON.stringify({ email: submittedEmail, _csrf_token: csrf.csrfToken }),
      });
      const result = await response.json().catch(() => ({})) as { message?: string };
      if (response.status === 429) throw new Error("Trop de demandes. Attendez avant de demander un autre lien.");
      if (!response.ok) throw new Error("Envoi impossible pour le moment. Réessayez plus tard.");
      setResendMessage(result.message ?? "Si l’adresse doit être confirmée, un nouveau lien vient d’être envoyé.");
    } catch (error) {
      setResendMessage(error instanceof Error ? error.message : "Envoi impossible pour le moment.");
    } finally {
      setResending(false);
    }
  }

  return <AccountLayout headline="Lancez la vente de vos tickets Wi-Fi." fullScreen>
    <h1 className="account-title">Créer un compte</h1>
    <p className="account-description">Inscrivez votre activité Wi-Fi sur Miksprit.</p>
    <Card className="login-card account-form-card signup-card"><CardContent>
      {submitted ? <div className="signup-complete" role="status" aria-live="polite"><p>{message}</p><Button asChild className="login-submit"><Link href="/connexion">Se connecter</Link></Button><Button type="button" variant="outline" disabled={resending} onClick={() => void resendVerification()}>{resending ? "Envoi…" : "Renvoyer le mail de confirmation"}</Button>{resendMessage && <p className="form-message" role="status">{resendMessage}</p>}</div> : <form onSubmit={submit}>
        <label htmlFor="email">Adresse e-mail</label>
        <input id="email" name="email" type="email" autoComplete="email" required maxLength={190} placeholder="vous@exemple.com" />
        <label htmlFor="password">Mot de passe</label>
        <input id="password" name="password" type="password" autoComplete="new-password" minLength={12} maxLength={4096} required />
        <p className="field-hint">12 caractères minimum</p>
        <label htmlFor="confirmation">Confirmer le mot de passe</label>
        <input id="confirmation" name="confirmation" type="password" autoComplete="new-password" minLength={12} maxLength={4096} required />
        {message && <p className="form-message signup-feedback is-error" role="alert" aria-live="assertive">{message}</p>}
        <Button className="login-submit" type="submit" disabled={busy}>{busy ? "Création…" : "Créer mon compte"}</Button>
      </form>}
    </CardContent></Card>
    {!submitted && <p className="account-form-note">Déjà inscrit ? <Link href="/connexion">Se connecter</Link></p>}
  </AccountLayout>;
}
