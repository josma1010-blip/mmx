/* Tienda eSIM & Chips – utilidades comunes (JavaScript puro) */
'use strict';
const App = (() => {
  const CART_KEY = 'tienda_cart_v1';
  let configPromise = null;

  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const money = (n) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(Number(n) || 0);
  const qs = (s, el = document) => el.querySelector(s);
  const qsa = (s, el = document) => [...el.querySelectorAll(s)];
  const safeImg = (p) => (typeof p === 'string' && /^(assets|uploads)\/[A-Za-z0-9_\-./]+$/.test(p) && !p.includes('..')) ? p : 'assets/img/placeholder.svg';

  async function api(path, opts = {}) {
    const o = { method: opts.method || 'GET', headers: { Accept: 'application/json' }, credentials: 'same-origin' };
    if (opts.body !== undefined) { o.headers['Content-Type'] = 'application/json'; o.body = JSON.stringify(opts.body); }
    if (o.method !== 'GET') { const c = await config(); o.headers['X-CSRF-Token'] = c.csrf; }
    let res, data;
    try { res = await fetch('api/' + path, o); } catch (e) { throw Object.assign(new Error('Sin conexión con el servidor'), { status: 0 }); }
    try { data = await res.json(); } catch (e) { data = { ok: false, error: 'Respuesta inválida del servidor' }; }
    if (!res.ok || !data.ok) throw Object.assign(new Error(data.error || 'Error'), { status: res.status, data });
    return data;
  }

  function config() {
    if (!configPromise) configPromise = fetch('api/config.php', { credentials: 'same-origin' }).then((r) => r.json()).then((d) => { if (!d.ok) throw new Error(d.error); return d; });
    return configPromise;
  }

  // ---------- Carrito (localStorage; el servidor siempre recalcula) ----------
  const cart = {
    get() { try { const c = JSON.parse(localStorage.getItem(CART_KEY) || '[]'); return Array.isArray(c) ? c.filter((i) => i && i.id > 0 && i.qty > 0) : []; } catch { return []; } },
    save(items) { localStorage.setItem(CART_KEY, JSON.stringify(items)); updateCount(); },
    add(p, qty = 1) {
      const items = cart.get(); const it = items.find((i) => i.id === p.id);
      const max = Math.min(20, p.stock ?? 20);
      if (it) it.qty = Math.min(max, it.qty + qty); else items.push({ id: p.id, qty: Math.min(max, qty), name: p.name, price: p.price, image: p.image, type: p.type });
      cart.save(items); toast('✅ ' + p.name + ' agregado al carrito');
    },
    setQty(id, qty) { const items = cart.get(); const it = items.find((i) => i.id === id); if (it) { it.qty = Math.max(1, Math.min(20, qty)); cart.save(items); } },
    remove(id) { cart.save(cart.get().filter((i) => i.id !== id)); },
    clear() { cart.save([]); },
    count() { return cart.get().reduce((a, i) => a + i.qty, 0); },
  };
  function updateCount() { qsa('[data-cart-count]').forEach((el) => { el.textContent = cart.count(); }); }

  let toastTimer;
  function toast(msg) {
    let t = qs('#toast');
    if (!t) { t = document.createElement('div'); t.id = 'toast'; t.className = 'toast'; t.setAttribute('role', 'status'); t.setAttribute('aria-live', 'polite'); document.body.appendChild(t); }
    t.textContent = msg; t.classList.add('show'); clearTimeout(toastTimer); toastTimer = setTimeout(() => t.classList.remove('show'), 2600);
  }

  function stockLabel(p) {
    if (!p.available || p.stock <= 0) return '<span class="stock no">Agotado</span>';
    if (p.stock <= 5) return `<span class="stock low">¡Últimas ${p.stock} piezas!</span>`;
    return '<span class="stock ok">Disponible</span>';
  }
  function productCard(p) {
    const url = 'producto.html?p=' + encodeURIComponent(p.slug);
    const off = p.compare_price && p.compare_price > p.price ? Math.round((1 - p.price / p.compare_price) * 100) : 0;
    return `<article class="card">
      <a class="card-img" href="${url}" aria-label="${esc(p.name)}">
        <img src="${esc(safeImg(p.image))}" alt="${esc(p.name)}" loading="lazy" width="400" height="300">
        <span class="tags"><span class="tag ${p.type === 'esim' ? 'light' : ''}">${p.type === 'esim' ? 'eSIM' : 'Chip físico'}</span>${off ? `<span class="tag red">-${off}%</span>` : ''}${p.is_example ? '<span class="tag ex">Ejemplo</span>' : ''}</span>
      </a>
      <div class="card-body">
        <span class="meta">${esc(p.company)} · ${esc(p.category)}</span>
        <h3><a href="${url}">${esc(p.name)}</a></h3>
        <p>${esc(p.short_description || '')}</p>
        <div class="card-foot">
          <div><div class="price">${money(p.price)}${p.compare_price && p.compare_price > p.price ? `<s>${money(p.compare_price)}</s>` : ''}</div>${stockLabel(p)}</div>
          <button class="btn btn-primary btn-sm" data-add="${p.id}" ${!p.available || p.stock <= 0 ? 'disabled' : ''} aria-label="Agregar ${esc(p.name)} al carrito">Comprar</button>
        </div>
      </div></article>`;
  }
  function bindAddButtons(container, products) {
    container.addEventListener('click', (e) => {
      const b = e.target.closest('[data-add]'); if (!b) return;
      const p = products.find((x) => x.id === Number(b.dataset.add)); if (p) cart.add(p, 1);
    });
  }

  function setFieldErrors(form, fields) {
    qsa('.field', form).forEach((f) => { f.classList.remove('invalid'); const e = qs('.err', f); if (e) e.textContent = ''; });
    let first = null;
    Object.entries(fields || {}).forEach(([name, msg]) => {
      const inp = form.elements[name]; if (!inp) return;
      const f = inp.closest('.field'); if (!f) return;
      f.classList.add('invalid'); let e = qs('.err', f);
      if (!e) { e = document.createElement('span'); e.className = 'err'; f.appendChild(e); }
      e.textContent = msg; inp.setAttribute('aria-invalid', 'true'); if (!first) first = inp;
    });
    if (first) first.focus();
  }

  async function initLayout() {
    const t = qs('.menu-toggle'), nav = qs('.nav');
    if (t && nav) t.addEventListener('click', () => { const o = nav.classList.toggle('open'); t.setAttribute('aria-expanded', o); });
    updateCount();
    window.addEventListener('storage', updateCount);
    const y = qs('[data-year]'); if (y) y.textContent = new Date().getFullYear();
    try {
      const { settings: s } = await config();
      qsa('[data-store-name]').forEach((el) => { el.textContent = s.store_name; });
      qsa('[data-store-tagline]').forEach((el) => { el.textContent = s.store_tagline; });
      qsa('[data-disclaimer]').forEach((el) => { el.textContent = s.footer_disclaimer; });
      qsa('[data-contact-email]').forEach((el) => { el.textContent = s.contact_email; if (el.tagName === 'A') el.href = 'mailto:' + s.contact_email; });
      qsa('[data-contact-phone]').forEach((el) => { el.textContent = s.contact_phone; if (el.tagName === 'A') el.href = 'tel:' + s.contact_phone.replace(/[^\d+]/g, ''); });
      qsa('[data-contact-address]').forEach((el) => { el.textContent = s.contact_address; });
      qsa('[data-about]').forEach((el) => { el.textContent = s.about_text; });
      const wa = qs('[data-whatsapp]');
      if (wa) { if (/^\d{8,15}$/.test(s.contact_whatsapp)) { wa.href = 'https://wa.me/' + s.contact_whatsapp; wa.hidden = false; } else wa.hidden = true; }
      if (document.title.includes('{store}')) document.title = document.title.replace('{store}', s.store_name);
    } catch (e) { console.warn(e); }
  }
  document.addEventListener('DOMContentLoaded', initLayout);
  return { esc, money, api, config, cart, toast, productCard, bindAddButtons, qs, qsa, safeImg, setFieldErrors, stockLabel };
})();
