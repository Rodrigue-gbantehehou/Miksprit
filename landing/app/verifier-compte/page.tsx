"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { AccountLayout } from "@/components/account-layout";

const apiBase = (process.env.NEXT_PUBLIC_API_BASE ?? "/api/v1").replace(/\/$/, "");

export default function VerifyOperatorPage() {
  const [message, setMessage] = useState("Confirmation de votre adresse e-mail…");
  const [done, setDone] = useState(false);
  useEffect(() => {
    let active = true;
    const token = new URLSearchParams(window.location.search).get("token") ?? "";
    async function verify() {
      try {
        const csrfResponse = await fetch(apiBase + "/auth/csrf", { credentials: "same-origin", cache: "no-store" });
        if (!csrfResponse.ok) throw new Error("Service momentanément indisponible.");
        const csrf = await csrfResponse.json() as { csrfToken: string };
        const response = await fetch(apiBase + "/public/operator-registrations/verify", { method: "POST", credentials: "same-origin", cache: "no-store", headers: { "Content-Type": "application/json", Accept: "application/json" }, body: JSON.stringify({ token, _csrf_token: csrf.csrfToken }) });
        const result = await response.json().catch(() => ({})) as { message?: string };
        if (!response.ok) throw new Error("Lien invalide ou expiré. Recommencez l’inscription.");
        if (active) { setMessage(result.message ?? "Adresse confirmée. Vous pouvez continuer à utiliser Miksprit."); setDone(true); }
      } catch (error) { if (active) setMessage(error instanceof Error ? error.message : "Vérification impossible."); }
    }
    void verify(); return () => { active = false; };
  }, []);
  return <AccountLayout action={<Link className="account-nav-link" href="/connexion">Connexion</Link>}>
    <h1 className="account-title">Vérifier votre adresse</h1>
    <Card className="login-card account-form-card"><CardContent>
      <p className="account-status" role="status" aria-live="polite">{message}</p>
      {done && <Button asChild className="login-submit"><Link href="/connexion">Se connecter</Link></Button>}
    </CardContent></Card>
  </AccountLayout>;
}
