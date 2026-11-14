import Link from "next/link";
import { ArrowDown, ArrowRight, Check, CreditCard, Router, Smartphone, Wifi } from "lucide-react";

const benefits = [
  {
    icon: Wifi,
    eyebrow: "ACCÈS CLIENT",
    title: "Un portail à votre image.",
    text: "Présentez vos forfaits sur une page de connexion simple. Vos clients choisissent leur accès et se connectent sans créer de compte Miksprit.",
  },
  {
    icon: Router,
    eyebrow: "GESTION DU RÉSEAU",
    title: "Vos sites, au même endroit.",
    text: "Organisez vos points d’accès, vos forfaits et vos routeurs depuis une console opérateur pensée pour le quotidien.",
  },
  {
    icon: CreditCard,
    eyebrow: "SUIVI DES REVENUS",
    title: "Chaque vente est lisible.",
    text: "Retrouvez les paiements, les frais FedaPay, la commission Miksprit et le montant dû à votre activité.",
  },
];

const steps = [
  { number: "01", title: "Créez votre espace", text: "Inscrivez-vous avec votre adresse e-mail et confirmez-la pour ouvrir votre espace propriétaire." },
  { number: "02", title: "Configurez votre réseau", text: "Ajoutez votre site, vos forfaits et votre routeur MikroTik depuis la console." },
  { number: "03", title: "Accueillez vos clients", text: "Vos clients choisissent un forfait sur le portail Wi-Fi. Le suivi des ventes reste dans votre espace." },
];

export default function Home() {
  return (
    <main className="marketing-shell">
      <header className="marketing-header wrap">
        <Link className="brand" href="/" aria-label="Miksprit, accueil">
          <span className="brand-mark">M</span><span>miksprit</span>
        </Link>
        <nav className="marketing-nav" aria-label="Navigation principale">
          <a href="#plateforme">La plateforme</a>
          <a href="#parcours">Comment ça marche</a>
          <Link className="marketing-login" href="/connexion">Se connecter</Link>
          <Link className="button button-primary marketing-signup" href="/inscription">Créer mon espace <ArrowRight size={16} aria-hidden="true" /></Link>
        </nav>
      </header>

      <section className="marketing-hero wrap">
        <div className="marketing-hero-copy">
          <p className="marketing-kicker"><span /> LA PLATEFORME DES OPÉRATEURS WI-FI</p>
          <h1>Votre Wi-Fi devient<br />une vraie <em>activité.</em></h1>
          <p className="marketing-lead">Vendez des accès Wi-Fi par forfait, gérez votre réseau et suivez vos revenus dans un seul espace conçu pour les opérateurs.</p>
          <div className="marketing-actions">
            <Link className="button button-primary marketing-main-cta" href="/inscription">Créer mon espace opérateur <ArrowRight size={17} aria-hidden="true" /></Link>
            <a className="marketing-secondary-cta" href="#plateforme">Découvrir Miksprit <ArrowDown size={15} aria-hidden="true" /></a>
          </div>
          <p className="marketing-reassurance"><Check size={15} aria-hidden="true" /> Inscription avec vérification par e-mail <span /> Aucun compte Miksprit requis pour vos clients
          </p>
        </div>

        <div className="product-preview" aria-label="Aperçu illustratif de la console opérateur Miksprit">
          <div className="preview-window-bar"><span className="preview-brand"><span className="preview-mark">M</span> Espace opérateur</span><span className="preview-live">Aperçu de la console</span></div>
          <div className="preview-layout">
            <aside className="preview-sidebar" aria-hidden="true"><span className="preview-side-active"><i /> Vue d’ensemble</span><span><i /> Mes sites</span><span><i /> Forfaits</span><span><i /> Routeurs</span><span><i /> Transactions</span></aside>
            <div className="preview-content">
              <div className="preview-greeting"><div><small>VOTRE ESPACE</small><strong>Vue d’ensemble</strong></div><span className="preview-avatar">OP</span></div>
              <div className="preview-stats">
                <article><small>Ventes</small><strong>Suivies</strong><span>Par forfait et par site</span></article>
                <article><small>Réseau</small><strong>Centralisé</strong><span>Sites et routeurs</span></article>
              </div>
              <div className="preview-activity">
                <div className="preview-activity-head"><strong>Votre activité</strong><span>Selon vos données</span></div>
                <div className="preview-chart" aria-hidden="true"><i /><i /><i /><i /><i /><i /><i /><i /><i /><i /><svg viewBox="0 0 440 90" preserveAspectRatio="none"><path d="M0 75 C35 72 38 48 78 56 S125 66 158 42 S206 58 243 35 S294 51 326 26 S374 43 405 16 S428 23 440 8" /></svg></div>
                <div className="preview-activity-foot"><span>Suivi des ventes</span><span className="preview-activity-status">Exemple d’affichage</span></div>
              </div>
              <div className="preview-transaction"><span className="preview-transaction-icon"><Smartphone size={16} /></span><span><strong>Paiement client</strong><small>Forfait Wi-Fi · confirmation sécurisée</small></span><b>Suivi</b></div>
            </div>
          </div>
          <div className="preview-caption"><span>APERÇU DE LA CONSOLE</span><span>PORTAIL · RÉSEAU · REVENUS</span></div>
        </div>
      </section>

      <section className="audience-strip" aria-label="Types de réseaux concernés">
        <div className="wrap audience-strip-inner"><p>FAIT POUR LES RÉSEAUX WI-FI QUI ACCUEILLENT DU PUBLIC</p><div><span>Hôtels & résidences</span><i /><span>Cafés & restaurants</span><i /><span>Campus</span><i /><span>Hotspots</span></div></div>
      </section>

      <section className="platform-section" id="plateforme">
        <div className="wrap">
          <div className="section-intro"><div><p className="marketing-kicker">TOUT AU MÊME ENDROIT</p><h2>Du premier accès<br />au suivi des ventes.</h2></div><p className="section-intro-copy">Miksprit relie l’expérience de vos clients aux outils dont vous avez besoin pour exploiter votre réseau.</p></div>
          <div className="benefit-grid">{benefits.map(({ icon: Icon, eyebrow, title, text }, index) => <article className="benefit-card" key={eyebrow}><div className="benefit-card-top"><span className="benefit-icon"><Icon size={20} strokeWidth={1.8} /></span><span>0{index + 1}</span></div><p>{eyebrow}</p><h3>{title}</h3><p className="benefit-text">{text}</p></article>)}</div>
        </div>
      </section>

      <section className="journey-section" id="parcours">
        <div className="wrap journey-layout">
          <div className="journey-intro"><p className="marketing-kicker">PRENEZ LE CONTRÔLE</p><h2>Un parcours clair,<br />de votre inscription<br />au premier client.</h2><p>Commencez avec un espace propriétaire. Ajoutez votre réseau quand vous êtes prêt.</p><Link className="button button-primary" href="/inscription">Commencer mon inscription <ArrowRight size={16} aria-hidden="true" /></Link></div>
          <div className="journey-steps">{steps.map((step) => <article className="journey-step" key={step.number}><span>{step.number}</span><div><h3>{step.title}</h3><p>{step.text}</p></div></article>)}</div>
        </div>
      </section>

      <section className="payments-section wrap">
        <div className="payments-panel"><div className="payments-copy"><p className="marketing-kicker">PAIEMENTS & TRANSPARENCE</p><h2>Vos ventes.<br />Le détail, sans détour.</h2><p>Pour les forfaits payés en ligne, Miksprit distingue le montant payé, les frais FedaPay, la commission de la plateforme et votre solde opérateur.</p><ul><li><Check size={16} /> Le client paie depuis le portail Wi-Fi</li><li><Check size={16} /> Le client n’a pas besoin de compte Miksprit</li><li><Check size={16} /> Vous demandez un reversement Mobile Money depuis votre espace</li></ul></div><div className="money-breakdown" aria-label="Détail illustratif du calcul d'une vente"><p>DÉTAIL D’UNE VENTE</p><div><span>Montant payé</span><b>100 %</b></div><div><span>Frais FedaPay</span><b>Déduits</b></div><div><span>Commission Miksprit</span><b>Configurée</b></div><div className="breakdown-total"><span>Solde opérateur</span><b>Calculé par vente</b></div><small>Les paiements en ligne et les reversements dépendent de l’activation du compte FedaPay.</small></div></div>
      </section>

      <section className="final-cta wrap"><div><p className="marketing-kicker">VOTRE RÉSEAU COMMENCE ICI</p><h2>Prêt à mieux gérer<br />votre Wi-Fi&nbsp;?</h2><p>Créez votre espace opérateur et configurez votre première activité.</p></div><div className="final-cta-actions"><Link className="button button-primary marketing-main-cta" href="/inscription">Créer mon compte opérateur <ArrowRight size={17} aria-hidden="true" /></Link><Link className="marketing-login" href="/connexion">J’ai déjà un compte</Link></div></section>

      <footer className="marketing-footer"><div className="wrap marketing-footer-inner"><Link className="brand" href="/"><span className="brand-mark">M</span><span>miksprit</span></Link><p>Le Wi-Fi, mieux organisé.</p><div><Link href="/inscription">Créer un espace</Link><Link href="/connexion">Connexion</Link></div><span className="copyright">© 2026 Miksprit</span></div></footer>
    </main>
  );
}
