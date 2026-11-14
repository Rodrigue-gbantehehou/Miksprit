"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";

const apiBase = (process.env.NEXT_PUBLIC_API_BASE ?? "/api/v1").replace(/\/$/, "");

export default function VerifyOperatorPage() {
  const [message, setMessage] = useState("Vérification de votre adresse e-mail…");
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
        if (!response.ok) throw new Error("Ce lien est invalide ou expiré. Recommencez l’inscription.");
        if (active) { setMessage(result.message ?? "Adresse confirmée. Votre espace est prêt."); setDone(true); }
      } catch (error) { if (active) setMessage(error instanceof Error ? error.message : "Vérification impossible."); }
    }
    void verify(); return () => { active = false; };
  }, []);
  return <main className="login-shell"><header className="header wrap"><Link className="brand" href="/"><span className="brand-mark">M</span>miksprit</Link></header><section className="wrap" style={{ maxWidth: 560, paddingTop: 80 }}><Card className="login-card"><CardHeader className="login-card-head"><span className="eyebrow">COMPTE OPÉRATEUR</span><CardTitle>Vérifier votre adresse</CardTitle><CardDescription role="status" aria-live="polite">{message}</CardDescription></CardHeader><CardContent>{done && <Link href="/connexion"><Button className="login-submit">Se connecter</Button></Link>}</CardContent></Card></section></main>;
}
