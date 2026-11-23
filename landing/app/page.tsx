import Link from "next/link";
import { DesktopMarketingNavigation } from "@/components/desktop-marketing-navigation";
import { MobileMarketingMenu } from "@/components/mobile-marketing-menu";

const capabilities = [
  {
    index: "01",
    title: "Publiez vos tickets",
    text: "Définissez le prix et la durée de chaque accès Wi-Fi.",
  },
  {
    index: "02",
    title: "Encaissez en ligne",
    text: "Le client paie en ligne. Le montant arrive sur votre compte marchand.",
  },
  {
    index: "03",
    title: "Suivez les ventes",
    text: "Consultez les transactions et les sessions depuis votre espace.",
  },
];

const setup = [
  { number: "01", title: "Créez votre espace", text: "Ajoutez votre zone Wi-Fi et vos tickets." },
  { number: "02", title: "Connectez votre routeur", text: "Enregistrez un MikroTik compatible et activez-le." },
  { number: "03", title: "Configurez le paiement", text: "Reliez votre compte marchand pour recevoir les paiements." },
];

export default function Home() {
  return (
    <div className="marketing-shell">
      <header className="marketing-header wrap">
        <Link className="brand" href="/" aria-label="Miksprit, accueil">
          <span className="brand-mark" aria-hidden="true">M</span><span>miksprit</span>
        </Link>
        <DesktopMarketingNavigation />
        <MobileMarketingMenu />
      </header>

      <main>
        <section className="marketing-hero" aria-labelledby="hero-title">
          <div className="marketing-hero-copy">
            <h1 id="hero-title">Votre Wi-Fi Zone vend, <em>même en votre absence.</em></h1>
            <p className="marketing-lead">Le client choisit un ticket, paie en ligne et reçoit son accès sans attendre le gérant.</p>
            <div className="marketing-actions">
              <Link className="marketing-main-cta" href="/inscription">Créer ma zone Wi-Fi</Link>
              <a className="marketing-secondary-cta" href="#fonctionnement">Voir le fonctionnement</a>
            </div>
          </div>

        </section>

        <section className="capabilities-section" id="outils" aria-labelledby="capabilities-title">
          <div className="wrap">
            <div className="section-heading">
              <p className="section-kicker">Dans votre espace</p>
              <h2 id="capabilities-title">Les outils pour exploiter<br />votre zone Wi-Fi.</h2>
            </div>
            <div className="capability-list">
              {capabilities.map((item) => (
                <article className="capability-row" key={item.index}>
                  <span className="capability-index">{item.index}</span>
                  <h3>{item.title}</h3>
                  <p>{item.text}</p>
                </article>
              ))}
            </div>
          </div>
        </section>

        <section className="customer-showcase" aria-labelledby="customer-showcase-title">
          <picture>
            <source media="(min-width: 681px)" srcSet="/images/customer-portal-wide.jpg" />
            <img src="/images/customer-portal.jpg" alt="Une femme souriante tient son téléphone et fait un signe positif." />
          </picture>
          <div className="customer-showcase-copy">
            <p className="section-kicker">Portail Wi-Fi</p>
            <h2 id="customer-showcase-title">Un accès simple<br />pour vos clients.</h2>
            <p>Vos tickets sont visibles et faciles à acheter depuis le portail Wi-Fi.</p>
          </div>
        </section>

        <section className="setup-section" id="fonctionnement" aria-labelledby="setup-title">
          <div className="wrap setup-layout">
            <div className="setup-intro">
              <p className="section-kicker">Mise en route</p>
              <h2 id="setup-title">Trois réglages<br />pour commencer.</h2>
              <p>Les paiements sont encaissés directement sur votre compte marchand.</p>
              <a className="text-cta" href="#paiements">Voir les paiements</a>
            </div>
            <ol className="setup-list">
              {setup.map((item) => (
                <li className="setup-step" key={item.number}>
                  <span>{item.number}</span>
                  <div><h3>{item.title}</h3><p>{item.text}</p></div>
                </li>
              ))}
            </ol>
          </div>
        </section>

        <section className="payment-section" id="paiements" aria-labelledby="payment-title">
          <div className="payment-inner">
            <div className="payment-copy">
              <p className="section-kicker">Encaissement</p>
              <h2 id="payment-title">Les paiements vont<br />sur votre compte marchand.</h2>
              <p>Reliez votre compte marchand à Miksprit pour recevoir les paiements directement.</p>
              <Link className="payment-link" href="/inscription">Créer un compte</Link>
            </div>
          </div>
        </section>
      </main>

      <footer className="marketing-footer">
        <div className="wrap marketing-footer-inner">
          <Link className="brand" href="/"><span className="brand-mark" aria-hidden="true">M</span><span>miksprit</span></Link>
          <p>Gestion des accès Wi-Fi.</p>
          <nav aria-label="Liens de pied de page">
            <Link href="/inscription">Créer un compte</Link>
            <Link href="/connexion">Connexion</Link>
            {process.env.NEXT_PUBLIC_CONTACT_EMAIL ? <a href={`mailto:${process.env.NEXT_PUBLIC_CONTACT_EMAIL}`}>Contact</a> : null}
          </nav>
          <span className="copyright">© 2026 Miksprit</span>
        </div>
      </footer>
    </div>
  );
}
