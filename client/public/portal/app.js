(() => {
  'use strict';

  const body = document.body;
  const apiBase = (body.dataset.apiBase || new URL('/api/v1', window.location.origin).toString()).replace(/\/$/, '');
  const siteId = new URLSearchParams(window.location.search).get('siteId') || body.dataset.siteId || '';
  const planList = document.querySelector('#plans');
  const siteName = document.querySelector('#site-name');
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
        throw new Error('Portail introuvable. Vérifiez le lien.');
      }
      if (response.status === 429) throw new Error('Trop de demandes. Réessayez plus tard.');
      if (payload.error === 'plan_not_available') throw new Error('Ce ticket n’est plus disponible. Actualisez la page.');
      if (payload.error === 'payment_temporarily_unavailable') throw new Error('Paiement indisponible. Réessayez plus tard.');
      if (payload.error === 'checkout_in_progress' && typeof payload.orderReference === 'string') {
        const conflict = new Error('Commande déjà créée. Vérification du paiement…');
        conflict.orderReference = payload.orderReference;
        throw conflict;
      }
      throw new Error('Tickets indisponibles. Réessayez plus tard.');
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
    const planName = String(plan.name ?? plan.title ?? 'Ticket Wi-Fi');
    name.textContent = planName;
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
    button.textContent = 'Choisir';
    button.setAttribute('aria-label', `Choisir ${planName}`);
    button.setAttribute('aria-pressed', 'false');
    button.addEventListener('click', () => {
      selectedPlan = plan;
      checkoutRequestKey = null;
      document.querySelectorAll('.plan-card').forEach((item) => {
        item.classList.remove('is-selected');
        const choice = item.querySelector('.choose-button');
        choice.setAttribute('aria-pressed', 'false');
        choice.textContent = 'Choisir';
      });
      card.classList.add('is-selected');
      button.setAttribute('aria-pressed', 'true');
      button.textContent = 'Choisi';
      checkoutForm.hidden = false;
      checkoutForm.querySelector('h3').textContent = `Payer ${planName} · ${price === null ? '' : `${currency.format(price)} XOF`}`;
      checkoutForm.querySelector('input').focus();
    });
    card.append(button);
    return card;
  }

  async function loadPlans() {
    if (!apiBase || !siteId) {
      setMessage('Site Wi-Fi manquant dans le lien.', true);
      planList.replaceChildren();
      planList.setAttribute('aria-busy', 'false');
      return;
    }
    planList.innerHTML = '<div class="loading-line"><span><i class="spinner" aria-hidden="true"></i> Chargement des tickets…</span></div>';
    try {
      const payload = await request(`/public/sites/${encodeURIComponent(siteId)}/plans`);
      if (payload.site?.id !== siteId || !Array.isArray(payload.plans)) {
        throw new Error('Tickets indisponibles. Réessayez plus tard.');
      }
      if (typeof payload.site.name === 'string' && payload.site.name.trim()) {
        siteName.textContent = payload.site.name.trim();
        document.title = `${payload.site.name.trim()} — Tickets Wi-Fi`;
      }
      const plans = plansFrom(payload).map(planCard).filter(Boolean);
      planList.replaceChildren();
      if (!plans.length) {
        setMessage('Aucun ticket disponible.');
      } else {
        plans.forEach((plan) => planList.append(plan));
      }
    } catch (error) {
      setMessage(error instanceof Error ? error.message : 'Chargement impossible.', true);
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
        throw new Error('Lien de paiement invalide. Contactez le personnel.');
      }
      window.location.assign(checkout.href);
    } catch (error) {
      if (error && typeof error.orderReference === 'string') {
        void pollPayment(error.orderReference);
        return;
      }
      setMessage(error instanceof Error ? error.message : 'Paiement impossible. Réessayez.', true);
      submit.disabled = false;
      submit.textContent = 'Continuer vers le paiement';
    }
  });

  checkoutForm.addEventListener('input', () => { checkoutRequestKey = null; });

  async function pollPayment(orderReference) {
    orderStatus.hidden = false;
    orderStatus.textContent = 'Vérification du paiement…';
    for (let attempt = 0; attempt < 30; attempt += 1) {
      try {
        const result = await request(`/public/orders/${encodeURIComponent(orderReference)}/status`);
        if (result.accessExpired) {
          showOrderMessage('Accès expiré', 'Choisissez un nouveau ticket.');
          return;
        }
        if (result.paymentStatus === 'succeeded' && result.orderStatus === 'fulfilled' && result.access) {
          showOrderCredentials(result.access.username, result.access.password);
          return;
        }
        if (['failed', 'expired'].includes(result.paymentStatus)) {
          showOrderMessage('Paiement non confirmé', 'Aucun accès activé. Vous pouvez réessayer.');
          return;
        }
        if (result.paymentStatus === 'succeeded' && result.orderStatus === 'provisioning_failed') {
          showOrderMessage('Paiement confirmé', 'Activation impossible. Contactez le personnel.');
          return;
        }
        if (result.paymentStatus === 'initiated') {
          orderStatus.textContent = 'Paiement en vérification. Ne payez pas à nouveau.';
        } else {
        orderStatus.textContent = result.paymentStatus === 'succeeded'
          ? 'Paiement confirmé. Activation de votre accès Wi-Fi en cours…'
          : 'Paiement en attente…';
        }
      } catch {
        orderStatus.textContent = 'Confirmation en attente. Actualisez bientôt.';
      }
      await new Promise((resolve) => window.setTimeout(resolve, Math.min(2000 + attempt * 250, 7000)));
    }
    orderStatus.replaceChildren(document.createTextNode('Paiement toujours en attente. Vous pouvez vérifier à nouveau.'));
    const retry = document.createElement('button');
    retry.className = 'choose-button';
    retry.type = 'button';
    retry.textContent = 'Vérifier encore';
    retry.addEventListener('click', () => { void pollPayment(orderReference); });
    orderStatus.append(retry);
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
