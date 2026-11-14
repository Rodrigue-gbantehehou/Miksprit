import { Suspense } from "react";
import { AccountLinkForm } from "@/components/account-link-form";

type ResetPasswordPageProps = { searchParams: Promise<Record<string, string | string[] | undefined>> };

export default function ResetPasswordPage({ searchParams }: ResetPasswordPageProps) {
  return (
    <Suspense fallback={<main className="login-shell"><section className="login-layout wrap"><p role="status">Chargement du lien sécurisé…</p></section></main>}>
      <ResetPasswordContent searchParams={searchParams} />
    </Suspense>
  );
}

async function ResetPasswordContent({ searchParams }: ResetPasswordPageProps) {
  const params = await searchParams;
  return <AccountLinkForm mode="reset" invitation={params.purpose === "invitation"} />;
}
