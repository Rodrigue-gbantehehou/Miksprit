import type { Metadata } from "next";
import "./globals.css";

export const metadata: Metadata = {
  title: "Miksprit — Espace propriétaire",
  description: "Console de gestion des réseaux Wi-Fi Miksprit.",
};

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="fr">
      <body>{children}</body>
    </html>
  );
}
