import type { Metadata } from "next";
import "./globals.css";

export const metadata: Metadata = {
  title: "Miksprit — Connectivité Wi-Fi, simplifiée",
  description: "Portail captif et gestion de réseaux Wi-Fi pour les opérateurs.",
};

export default function RootLayout({ children }: LayoutProps<"/">) {
  return (
    <html lang="fr">
      <body>{children}</body>
    </html>
  );
}
