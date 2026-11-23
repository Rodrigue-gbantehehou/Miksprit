import { Suspense } from "react";
import { AccountLinkForm } from "@/components/account-link-form";
import { AccountLayout } from "@/components/account-layout";

type ResetPasswordPageProps = { searchParams: Promise<Record<string, string | string[] | undefined>> };

export default function ResetPasswordPage({ searchParams }: ResetPasswordPageProps) {
  return (
    <Suspense fallback={<AccountLayout><p className="account-status" role="status">Chargement du lien…</p></AccountLayout>}>
      <ResetPasswordContent searchParams={searchParams} />
    </Suspense>
  );
}

async function ResetPasswordContent({ searchParams }: ResetPasswordPageProps) {
  const params = await searchParams;
  return <AccountLinkForm mode="reset" invitation={params.purpose === "invitation"} />;
}
