(() => {
  'use strict';

  const body = document.body;
  const apiBase = (body.dataset.apiBase || new URL('/api/v1', window.location.origin).toString()).replace(/\/$/, '');
  const siteId = new URLSearchParams(window.location.search).get('siteId') || body.dataset.siteId || '';
  const planList = document.querySelector('#plans');
  const message = document.querySelector('#message');
  const checkoutForm = document.querySelector('#checkout-form');
  const orderStatus = document.querySelector('#order-status');
  const currency = new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 0 });
  let selectedPlan = null;
  let checkoutRequestKey = null;

  function setMessage(text, isError = false) {
    message.textContent = text;
    message.classList.toggle('error', isError);
    message.hidden = !text;
  }

  async function request(path, options = {}) {
    const response = await fetch(`${apiBase}${path}`, {
      ...options,
      headers: { Accept: 'application/json', ...(options.body ? { 'Content-Type': 'application/json' } : {}), ...(options.headers || {}) },
    });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok) {
      if (response.status === 404 && path.includes('/plans')) {
        throw new Error('Ce portail Wi-Fi est introuvable ou momentanément désactivé. Vérifiez le lien reçu ou demandez de l’aide au personnel sur place.');
      }
      if (response.status === 429) throw new Error('Trop de demandes depuis ce réseau. Patientez quelques minutes avant de réessayer.');
      if (payload.error === 'plan_not_available') throw new Error('Ce forfait n’est plus disponible. Actualisez la page pour voir les offres en cours.');
      if (payload.error === 'payment_temporarily_unavailable') throw new Error('Le paiement est momentanément indisponible. Réessayez plus tard ou demandez de l’aide au personnel.');
      if (payload.error === 'checkout_in_progress' && typeof payload.orderReference === 'string') {
        const conflict = new Error('Cette demande a déjà créé une commande. Vérification de son état…');
        conflict.orderReference = payload.orderReference;
        throw conflict;
      }
      throw new Error('Les forfaits sont momentanément indisponibles. Réessayez plus tard ou demandez de l’aide au personnel sur place.');
    }
    return payload;
  }

  function plansFrom(payload) {
    if (Array.isArray(payload)) return payload;
    if (Array.isArray(payload.plans)) return payload.plans;
    if (payload.data && Array.isArray(payload.data.plans)) return payload.data.plans;
    return [];
  }

  function amountOf(plan) {
    const amount = plan.amountXof ?? plan.priceXof ?? plan.amount;
    if (typeof amount === 'number' && Number.isFinite(amount) && amount >= 0) return amount;
    if (amount && typeof amount === 'object' && Number.isFinite(Number(amount.minorUnits))) return Number(amount.minorUnits);
    return null;
  }

  function planCard(plan) {
    const id = String(plan.id ?? plan.publicId ?? '');
    if (!id) return null;

    const card = document.createElement('article');
    card.className = 'plan-card';

    const top = document.createElement('div');
    top.className = 'plan-top';
    const radio = document.createElement('span');
    radio.className = 'radio-mark';
    radio.setAttribute('aria-hidden', 'true');
    const details = document.createElement('div');
    details.className = 'plan-details';
    const name = document.createElement('h3');
    name.className = 'plan-name';
    name.textContent = String(plan.name ?? plan.title ?? 'Forfait Wi-Fi');
    details.append(name);
    const descriptionText = plan.description;
    if (typeof descriptionText === 'string' && descriptionText.trim()) {
      const description = document.createElement('p');
      description.className = 'plan-description';
      description.textContent = descriptionText;
      details.append(description);
    }
    const price = amountOf(plan);
    const priceNode = document.createElement('strong');
    priceNode.className = 'plan-price';
    priceNode.textContent = price === null ? 'Prix indisponible' : `${currency.format(price)} XOF`;
    top.append(radio, details, priceNode);
    card.append(top);

    const meta = document.createElement('div');
    meta.className = 'plan-meta';
    const duration = plan.durationLabel ?? plan.validityLabel;
    if (typeof duration === 'string' && duration) {
      const pill = document.createElement('span');
      pill.className = 'meta-pill';
      pill.textContent = duration;
      meta.append(pill);
    } else if (Number.isFinite(Number(plan.sessionSeconds))) {
      const pill = document.createElement('span');
      pill.className = 'meta-pill';
      pill.textContent = `${Math.round(Number(plan.sessionSeconds) / 60)} min d’accès`;
      meta.append(pill);
    }
    if (Number.isFinite(Number(plan.dataLimitBytes)) && Number(plan.dataLimitBytes) > 0) {
      const pill = document.createElement('span');
      pill.className = 'meta-pill';
      pill.textContent = `${(Number(plan.dataLimitBytes) / (1024 ** 3)).toFixed(1)} Go`;
      meta.append(pill);
    }
    if (meta.childElementCount) card.append(meta);

    const button = document.createElement('button');
    button.className = 'choose-button';
    button.type = 'button';
    button.textContent = 'Choisir ce forfait';
    button.addEventListener('click', () => {
      selectedPlan = plan;
      checkoutRequestKey = null;
      document.querySelectorAll('.plan-card').forEach((item) => item.classList.remove('is-selected'));
      card.classList.add('is-selected');
      checkoutForm.hidden = false;
      checkoutForm.querySelector('h3').textContent = `Payer ${String(plan.name ?? 'ce forfait')} · ${price === null ? '' : `${currency.format(price)} XOF`}`;
      checkoutForm.querySelector('input').focus();
    });
    card.append(button);
    return card;
  }

  async function loadPlans() {
    if (!apiBase || !siteId) {
      setMessage('Identifiant de site manquant. Ouvrez le lien du portail fourni par le lieu ou demandez de l’aide au personnel.', true);
      planList.replaceChildren();
      planList.setAttribute('aria-busy', 'false');
      return;
    }
    planList.innerHTML = '<div class="loading-line"><span><i class="spinner" aria-hidden="true"></i> Chargement des forfaits…</span></div>';
    try {
      const payload = await request(`/public/sites/${encodeURIComponent(siteId)}/plans`);
      if (payload.site?.id !== siteId || !Array.isArray(payload.plans)) {
        throw new Error('Le catalogue reçu est invalide. Réessayez plus tard ou demandez de l’aide au personnel.');
      }
      const plans = plansFrom(payload).map(planCard).filter(Boolean);
      planList.replaceChildren();
      if (!plans.length) {
        setMessage('Aucun forfait n’est disponible sur ce réseau pour le moment. Demandez de l’aide au personnel sur place.');
      } else {
        plans.forEach((plan) => planList.append(plan));
        setMessage('Choisissez un forfait pour commencer. Le paiement est confirmé côté serveur avant l’activation de votre accès.');
      }
    } catch (error) {
      setMessage(error instanceof Error ? error.message : 'Les forfaits ne peuvent pas être chargés. Réessayez plus tard.', true);
      planList.replaceChildren();
    } finally {
      planList.setAttribute('aria-busy', 'false');
    }
  }

  checkoutForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (!selectedPlan || !siteId) return;
    if (!checkoutRequestKey) checkoutRequestKey = window.crypto.randomUUID();
    const submit = checkoutForm.querySelector('button[type="submit"]');
    const data = new FormData(checkoutForm);
    submit.disabled = true;
    submit.textContent = 'Préparation du paiement…';
    setMessage('');
    try {
      const result = await request(`/public/sites/${encodeURIComponent(siteId)}/checkout`, {
        method: 'POST',
        headers: { 'Idempotency-Key': checkoutRequestKey },
        body: JSON.stringify({
          planId: String(selectedPlan.id),
          email: String(data.get('email') || ''),
          firstName: String(data.get('firstName') || '') || null,
          lastName: String(data.get('lastName') || '') || null,
        }),
      });
      const checkout = new URL(result.checkoutUrl);
      if (checkout.protocol !== 'https:' || !(checkout.hostname === 'fedapay.com' || checkout.hostname.endsWith('.fedapay.com'))) {
        throw new Error('Le lien de paiement reçu n’est pas valide. Contactez le personnel sur place.');
      }
      window.location.assign(checkout.href);
    } catch (error) {
      if (error && typeof error.orderReference === 'string') {
        void pollPayment(error.orderReference);
        return;
      }
      setMessage(error instanceof Error ? error.message : 'Le paiement ne peut pas démarrer. Réessayez plus tard.', true);
      submit.disabled = false;
      submit.textContent = 'Continuer vers le paiement';
    }
  });

  checkoutForm.addEventListener('input', () => { checkoutRequestKey = null; });

  async function pollPayment(orderReference) {
    orderStatus.hidden = false;
    orderStatus.textContent = 'Vérification du paiement auprès du serveur…';
    for (let attempt = 0; attempt < 30; attempt += 1) {
      try {
        const result = await request(`/public/orders/${encodeURIComponent(orderReference)}/status`);
        if (result.accessExpired) {
          showOrderMessage('Accès expiré', 'Ce forfait a atteint sa date d’expiration. Choisissez un nouveau forfait pour vous reconnecter.');
          return;
        }
        if (result.paymentStatus === 'succeeded' && result.orderStatus === 'fulfilled' && result.access) {
          showOrderCredentials(result.access.username, result.access.password);
          return;
        }
        if (['failed', 'expired'].includes(result.paymentStatus)) {
          showOrderMessage('Paiement non confirmé', 'Aucun accès n’a été activé. Vous pouvez choisir un forfait et recommencer.');
          return;
        }
        if (result.paymentStatus === 'succeeded' && result.orderStatus === 'provisioning_failed') {
          showOrderMessage('Paiement confirmé · assistance requise', 'Le routeur n’a pas confirmé l’activation de votre accès. Présentez cette page au personnel sur place.');
          return;
        }
        if (result.paymentStatus === 'initiated') {
          orderStatus.textContent = 'La demande de paiement est en cours de vérification. Ne recommencez pas le paiement; demandez de l’aide au personnel avec cette référence.';
        } else {
        orderStatus.textContent = result.paymentStatus === 'succeeded'
          ? 'Paiement confirmé. Activation de votre accès Wi-Fi en cours…'
          : 'Paiement en attente de confirmation sécurisée…';
        }
      } catch {
        orderStatus.textContent = 'La confirmation n’est pas encore disponible. Actualisez dans quelques instants.';
      }
      await new Promise((resolve) => window.setTimeout(resolve, Math.min(2000 + attempt * 250, 7000)));
    }
    orderStatus.textContent = 'Le paiement n’a pas encore été confirmé. Gardez cette page ouverte ou demandez de l’aide au personnel.';
  }

  function showOrderMessage(title, description) {
    const heading = document.createElement('strong');
    heading.textContent = title;
    orderStatus.replaceChildren(heading, document.createTextNode(description));
  }

  function showOrderCredentials(username, password) {
    const heading = document.createElement('strong');
    heading.textContent = 'Paiement confirmé · votre accès Wi-Fi';
    const credentials = document.createElement('div');
    credentials.className = 'voucher';
    const login = document.createElement('span');
    login.textContent = `Identifiant : ${username}`;
    const secret = document.createElement('span');
    secret.textContent = `Mot de passe : ${password}`;
    credentials.append(login, secret);
    orderStatus.replaceChildren(heading, credentials);
  }

  loadPlans();
  const returnedOrder = new URLSearchParams(window.location.search).get('order_reference');
  if (returnedOrder && /^[a-f0-9]{64}$/.test(returnedOrder)) void pollPayment(returnedOrder);
})();
