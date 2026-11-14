import { AccountLinkForm } from "@/components/account-link-form";

export default async function ResetPasswordPage({ searchParams }: { searchParams: Promise<Record<string, string | string[] | undefined>> }) {
  const params = await searchParams;
  return <AccountLinkForm mode="reset" invitation={params.purpose === "invitation"} />;
}
