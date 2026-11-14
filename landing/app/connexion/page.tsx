"use client";

import Link from "next/link";
import { FormEvent, useState } from "react";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";

type LoginResponse = { user: { id: string; email: string }; availableApps: Array<"client" | "admin">; defaultApp: "client" | "admin" | null };
const apiBase = (process.env.NEXT_PUBLIC_API_BASE ?? "/api/v1").replace(/\/$/, "");
const destinations = {
  client: process.env.NEXT_PUBLIC_CLIENT_APP_URL ?? "http://localhost:3000",
  admin: process.env.NEXT_PUBLIC_ADMIN_APP_URL ?? "http://localhost:3001",
};

function allowedReturnTo(value: string | null, apps: LoginResponse["availableApps"]): string | null {
  if (!value) return null;
  try {
    const candidate = new URL(value);
    const app = (Object.keys(destinations) as Array<keyof typeof destinations>).find((key) => new URL(destinations[key]).origin === candidate.origin);
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
      if (!csrfResponse.ok) throw new Error("Le service de connexion est momentanément indisponible.");
      const csrf = await csrfResponse.json() as { csrfToken: string };
      const response = await fetch(`${apiBase}/auth/login`, { method: "POST", credentials: "same-origin", cache: "no-store", headers: { Accept: "application/json", "Content-Type": "application/json" }, body: JSON.stringify({ email: form.get("email"), password: form.get("password"), _csrf_token: csrf.csrfToken }) });
      const result = await response.json().catch(() => ({})) as LoginResponse & { error?: string };
      if (!response.ok) throw new Error(response.status === 401 ? "Adresse e-mail ou mot de passe incorrect." : result.error ?? "La connexion a échoué. Réessayez.");
      const returnTo = allowedReturnTo(new URLSearchParams(window.location.search).get("returnTo"), result.availableApps ?? []);
      if (returnTo) { window.location.assign(returnTo); return; }
      if (result.defaultApp && result.availableApps?.includes(result.defaultApp)) { window.location.assign(destinations[result.defaultApp]); return; }
      setMessage("Votre compte est reconnu, mais aucun espace Miksprit ne lui est encore attribué.");
    } catch (error) { setMessage(error instanceof Error ? error.message : "Impossible de joindre le service de connexion."); }
    finally { setBusy(false); }
  }

  return <main className="login-shell"><header className="header wrap"><Link className="brand" href="/"><span className="brand-mark">M</span>miksprit</Link><Link className="text-link" href="/">Retour à l’accueil <span>↗</span></Link></header><section className="login-layout wrap"><div className="login-intro"><p className="eyebrow">ESPACE SÉCURISÉ</p><h1>Ravi de vous<br/><em>retrouver.</em></h1><p>Connectez-vous à votre espace Miksprit. Votre accès vous dirige vers la console correspondant à vos autorisations.</p><div className="login-rule"><span className="status-dot"/> Une connexion unique pour vos espaces autorisés</div></div><Card className="login-card"><CardHeader className="login-card-head"><span className="eyebrow">VOTRE COMPTE</span><CardTitle>Se connecter</CardTitle><CardDescription>Entrez les identifiants de votre compte Miksprit.</CardDescription></CardHeader><CardContent><form onSubmit={submit}><label htmlFor="email">Adresse e-mail</label><input autoComplete="username" id="email" name="email" type="email" required placeholder="nom@entreprise.com"/><label htmlFor="password">Mot de passe</label><input autoComplete="current-password" id="password" name="password" type="password" required placeholder="Votre mot de passe"/><Link className="forgot-link" href="/mot-de-passe-oublie">Mot de passe oublié ?</Link><Button className="login-submit" type="submit" disabled={busy}>{busy ? "Connexion…" : "Continuer"}<span aria-hidden>↗</span></Button><p className="form-message" role="status" aria-live="polite">{message}</p></form><div className="login-card-foot">Vous gérez un réseau Wi-Fi ? <Link href="/inscription">Créer un espace opérateur</Link></div></CardContent></Card></section><footer className="footer wrap"><Link className="brand" href="/"><span className="brand-mark">M</span>miksprit</Link><span>© 2026 Miksprit · Connexion protégée</span></footer></main>;
}
