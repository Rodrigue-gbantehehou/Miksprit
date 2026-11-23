import Link from "next/link";
import { ArrowRight } from "lucide-react";
import { marketingSectionLinks } from "@/components/marketing-navigation";

export function DesktopMarketingNavigation() {
  return (
    <nav className="marketing-nav" aria-label="Navigation principale">
      {marketingSectionLinks.map((item) => <a href={item.href} key={item.href}>{item.label}</a>)}
      <Link className="marketing-login" href="/connexion">Connexion</Link>
      <Link className="marketing-signup" href="/inscription">Créer un compte <ArrowRight size={16} aria-hidden="true" /></Link>
    </nav>
  );
}
