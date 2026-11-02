'use strict';
(() => {
  const { esc, api, money, qs, cart, safeImg } = App;
  const list = qs('#cart-items'), alerts = qs('#cart-alerts'), go = qs('#go-checkout');
  async function render() {
    const items = cart.get();
    if (!items.length) {
      list.innerHTML = '<div class="empty"><div class="ic">🛒</div><h2>Tu carrito está vacío</h2><p>Agrega una eSIM o un chip para continuar.</p><a class="btn btn-primary" href="productos.html">Ver catálogo</a></div>';
      ['#s-sub', '#s-ship', '#s-total'].forEach((s) => (qs(s).textContent = money(0)));
      go.classList.add('disabled'); go.setAttribute('aria-disabled', 'true'); alerts.innerHTML = ''; qs('#ship-hint').textContent = ''; return;
    }
    let quote;
    try { quote = await api('cart.php', { method: 'POST', body: { items: items.map((i) => ({ id: i.id, qty: i.qty })) } }); }
    catch (e) { alerts.innerHTML = `<div class="alert error">${esc(e.message)}</div>`; return; }
    const byId = Object.fromEntries(quote.lines.map((l) => [l.product_id, l]));
    const errById = Object.fromEntries(quote.errors.map((x) => [x.id, x]));
    // Sincroniza precios guardados con los del servidor
    cart.save(items.map((i) => (byId[i.id] ? { ...i, price: byId[i.id].unit_price, name: byId[i.id].name } : i)));
    list.innerHTML = items.map((i) => {
      const l = byId[i.id], err = errById[i.id];
      return `<div class="cart-item" data-id="${i.id}">
        <img src="${esc(safeImg(i.image))}" alt="" width="80" height="60">
        <div><b>${esc(l ? l.name : i.name || 'Producto')}</b><div class="hint">${(l ? l.type : i.type) === 'esim' ? 'eSIM digital' : 'Chip físico'} · ${money(l ? l.unit_price : i.price)} c/u</div>
          ${err ? `<div class="err" role="alert">${esc(err.error)}</div>` : ''}</div>
        <div class="qty"><button type="button" data-d="-1" aria-label="Quitar uno">−</button><input type="number" min="1" max="20" value="${i.qty}" aria-label="Cantidad"><button type="button" data-d="1" aria-label="Agregar uno">+</button></div>
        <div class="line">${l ? money(l.line_total) : '—'}</div>
        <button class="btn btn-ghost btn-sm" data-rm aria-label="Eliminar">✕</button></div>`;
    }).join('');
    alerts.innerHTML = quote.errors.length ? '<div class="alert warn">Algunos productos no tienen stock suficiente. Ajusta las cantidades para continuar.</div>' : '';
    qs('#s-sub').textContent = money(quote.subtotal);
    qs('#s-ship').textContent = quote.has_physical ? (quote.shipping ? money(quote.shipping) : 'Gratis') : 'No aplica';
    qs('#s-total').textContent = money(quote.total);
    const c = await App.config().catch(() => null);
    qs('#ship-hint').textContent = quote.has_physical && c && +c.settings.free_shipping_from > 0 ? `Envío gratis en chips desde ${money(c.settings.free_shipping_from)}. Las eSIM no pagan envío.` : (quote.has_physical ? '' : 'Las eSIM se entregan digitalmente, sin costo de envío.');
    const bad = quote.errors.length > 0;
    go.classList.toggle('disabled', bad); go.setAttribute('aria-disabled', bad);
  }
  list.addEventListener('click', (e) => {
    const row = e.target.closest('.cart-item'); if (!row) return; const id = +row.dataset.id;
    if (e.target.closest('[data-rm]')) { cart.remove(id); render(); return; }
    const d = e.target.closest('[data-d]'); if (d) { const inp = row.querySelector('input'); cart.setQty(id, (+inp.value || 1) + +d.dataset.d); render(); }
  });
  list.addEventListener('change', (e) => { const row = e.target.closest('.cart-item'); if (row && e.target.matches('input')) { cart.setQty(+row.dataset.id, +e.target.value || 1); render(); } });
  go.addEventListener('click', (e) => { if (go.getAttribute('aria-disabled') === 'true') e.preventDefault(); });
  render();
})();
