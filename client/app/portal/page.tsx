import type { Metadata } from "next";

export const metadata: Metadata = {
  title: "Portail Wi-Fi — Miksprit",
  robots: { index: false, follow: false },
};

export const instant = false;

export default async function CaptivePortalPage({
  searchParams,
}: {
  searchParams: Promise<{ siteId?: string | string[]; order_reference?: string | string[] }>;
}) {
  const params = await searchParams;
  const siteId = Array.isArray(params.siteId) ? params.siteId[0] : params.siteId;
  const orderReference = Array.isArray(params.order_reference) ? params.order_reference[0] : params.order_reference;
  const query = new URLSearchParams();
  if (siteId) query.set("siteId", siteId);
  if (orderReference) query.set("order_reference", orderReference);
  const portalUrl = `/portal/index.html${query.size ? `?${query.toString()}` : ""}`;

  return (
    <iframe
      title="Portail de connexion Wi-Fi Miksprit"
      src={portalUrl}
      style={{ position: "fixed", inset: 0, width: "100%", height: "100dvh", border: 0 }}
    />
  );
}
