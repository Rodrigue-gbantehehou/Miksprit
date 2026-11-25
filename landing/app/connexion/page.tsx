"use client";

import Link from "next/link";
import { FormEvent, useState } from "react";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { AccountLayout } from "@/components/account-layout";

type LoginResponse = { user: { id: string; email: string }; availableApps: Array<"client" | "admin">; defaultApp: "client" | "admin" | null };
const apiBase = (process.env.NEXT_PUBLIC_API_BASE ?? "/api/v1").replace(/\/$/, "");
const destinations = {
  client: process.env.NEXT_PUBLIC_CLIENT_APP_URL ?? (process.env.NODE_ENV === "development" ? "http://localhost:3000" : "https://mymik.poulvia.com"),
  admin: process.env.NEXT_PUBLIC_ADMIN_APP_URL ?? (process.env.NODE_ENV === "development" ? "http://localhost:3001" : "https://mikhome.poulvia.com"),
};

function allowedReturnTo(value: string | null, apps: LoginResponse["availableApps"]): string | null {
  if (!value) return null;
  try {
    const candidate = new URL(value);
    const app = (Object.keys(destinations) as Array<keyof typeof destinations>).find((key) => destinations[key] !== "" && new URL(destinations[key]).origin === candidate.origin);
    return app && apps.includes(app) && candidate.username === "" && candidate.password === "" && candidate.pathname === "/" && candidate.search === "" && candidate.hash === "" ? candidate.toString() : null;
  } catch { return null; }
}

export default function LoginPage() {
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState("");

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); setBusy(true); setMessage("");
    const form = new FormData(event.currentTarget);
    try {
      const csrfResponse = await fetch(`${apiBase}/auth/csrf`, { credentials: "same-origin", cache: "no-store", headers: { Accept: "application/json" } });
      if (!csrfResponse.ok) throw new Error("Connexion momentanément indisponible.");
      const csrf = await csrfResponse.json() as { csrfToken: string };
      const response = await fetch(`${apiBase}/auth/login`, { method: "POST", credentials: "same-origin", cache: "no-store", headers: { Accept: "application/json", "Content-Type": "application/json" }, body: JSON.stringify({ email: form.get("email"), password: form.get("password"), _csrf_token: csrf.csrfToken }) });
      const result = await response.json().catch(() => ({})) as LoginResponse & { error?: string };
      if (!response.ok) throw new Error(response.status === 401 ? "Adresse e-mail ou mot de passe incorrect." : result.error ?? "La connexion a échoué. Réessayez.");
      const returnTo = allowedReturnTo(new URLSearchParams(window.location.search).get("returnTo"), result.availableApps ?? []);
      if (returnTo) { window.location.assign(returnTo); return; }
      if (result.defaultApp && result.availableApps?.includes(result.defaultApp) && destinations[result.defaultApp]) { window.location.assign(destinations[result.defaultApp]); return; }
      if (destinations.client) { window.location.assign(destinations.client); return; }
      setMessage("Connexion réussie, mais aucun espace n’est configuré.");
    } catch (error) { setMessage(error instanceof Error ? error.message : "Impossible de joindre le service de connexion."); }
    finally { setBusy(false); }
  }

  return <AccountLayout headline="Vos ventes Wi-Fi, en un seul espace." fullScreen>
    <h1 className="account-title">Connexion</h1>
    <p className="account-description">Accédez à vos sites, tickets et ventes.</p>
    <Card className="login-card account-form-card"><CardContent>
      <form onSubmit={submit}>
        <label htmlFor="email">Adresse e-mail</label>
        <input autoComplete="username" id="email" name="email" type="email" required placeholder="vous@exemple.com"/>
        <label htmlFor="password">Mot de passe</label>
        <input autoComplete="current-password" id="password" name="password" type="password" required/>
        <Link className="forgot-link" href="/mot-de-passe-oublie">Mot de passe oublié ?</Link>
        <Button className="login-submit" type="submit" disabled={busy}>{busy ? "Connexion…" : "Se connecter"}</Button>
        {message && <p className="form-message" role="status" aria-live="polite">{message}</p>}
      </form>
      <p className="account-form-note">Pas encore de compte ? <Link href="/inscription">Créer un compte</Link></p>
    </CardContent></Card>
  </AccountLayout>;
}
