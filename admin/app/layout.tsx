import type { Metadata } from "next";
import "./globals.css";

export const metadata: Metadata = {
  title: "Miksprit — Administration globale",
  description: "Console centrale d’administration de la plateforme Miksprit.",
};

export default function RootLayout({ children }: LayoutProps<"/">) {
  return (
    <html lang="fr">
      <body>{children}</body>
    </html>
  );
}
