import type { Metadata } from "next";
import "./globals.css";

export const metadata: Metadata = {
  title: "Miksprit — Gérez votre activité Wi-Fi",
  description: "Vendez des accès Wi-Fi par forfait, gérez votre réseau et suivez vos revenus depuis une seule plateforme opérateur.",
};

export default function RootLayout({ children }: LayoutProps<"/">) {
  return (
    <html lang="fr">
      <body>{children}</body>
    </html>
  );
}
