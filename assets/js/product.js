'use strict';
(async () => {
  const { esc, api, money, productCard, bindAddButtons, qs, cart, safeImg, stockLabel } = App;
  const box = qs('#product');
  const slug = new URLSearchParams(location.search).get('p') || '';
  let p;
  try { p = (await api('products.php?slug=' + encodeURIComponent(slug))).product; }
  catch (e) { box.innerHTML = `<div class="empty"><div class="ic">📦</div><h1>Producto no encontrado</h1><a class="btn btn-primary" href="productos.html">Ver catálogo</a></div>`; return; }
  document.title = p.name + ' | ' + document.title.split('|').pop().trim();
  const md = document.querySelector('meta[name=description]'); if (md) md.content = p.short_description || p.name;
  qs('#bc-name').textContent = p.name;
  const imgs = p.images.length ? p.images : [{ path: p.image, alt: p.name }];
  const max = Math.max(1, Math.min(20, p.stock));
  box.innerHTML = `<div class="pdetail">
    <div class="gallery"><img id="main-img" src="${esc(safeImg(imgs[0].path))}" alt="${esc(imgs[0].alt || p.name)}" width="600" height="450">
      ${imgs.length > 1 ? `<div class="thumbs">${imgs.map((im, i) => `<button type="button" data-i="${i}" class="${i ? '' : 'active'}" aria-label="Ver imagen ${i + 1}"><img src="${esc(safeImg(im.path))}" alt=""></button>`).join('')}</div>` : ''}</div>
    <div>
      <div class="tags" style="position:static;margin-bottom:.6rem"><span class="tag ${p.type === 'esim' ? 'light' : ''}">${p.type === 'esim' ? 'eSIM digital' : 'Chip físico'}</span><span class="tag">${esc(p.company)}</span>${p.is_example ? '<span class="tag ex">Producto de ejemplo</span>' : ''}</div>
      <h1>${esc(p.name)}</h1>
      <p class="lead">${esc(p.short_description || '')}</p>
      <div class="price big">${money(p.price)}${p.compare_price && p.compare_price > p.price ? ` <s>${money(p.compare_price)}</s>` : ''}</div>
      <p>${stockLabel(p)}</p>
      <div class="buy-row"><label for="qty" class="sr-only">Cantidad</label>
        <div class="qty"><button type="button" data-d="-1" aria-label="Menos">−</button><input id="qty" type="number" min="1" max="${max}" value="1"><button type="button" data-d="1" aria-label="Más">+</button></div>
        <button class="btn btn-red" id="add" ${p.available ? '' : 'disabled'}>Agregar al carrito</button></div>
      <div class="alert info">${p.type === 'esim' ? '📲 Recibirás el código QR y los datos de activación por correo y en tu página de pedido, una vez confirmado el pago. Verifica que tu equipo sea compatible con eSIM y esté liberado.' : '🚚 Se envía a tu domicilio. El costo de envío se calcula en el carrito.'}</div>
      <div class="desc">${esc(p.description || '')}</div>
    </div></div>`;
  const qty = qs('#qty');
  box.addEventListener('click', (e) => {
    const d = e.target.closest('[data-d]'); if (d) qty.value = Math.max(1, Math.min(max, (+qty.value || 1) + +d.dataset.d));
    const t = e.target.closest('[data-i]');
    if (t) { const im = imgs[+t.dataset.i]; qs('#main-img').src = safeImg(im.path); box.querySelectorAll('.thumbs button').forEach((b) => b.classList.toggle('active', b === t)); }
  });
  qs('#add').addEventListener('click', () => cart.add(p, Math.max(1, Math.min(max, +qty.value || 1))));
  try {
    const { products } = await api('products.php?limit=4&category=' + encodeURIComponent(p.category_slug));
    const rel = products.filter((x) => x.id !== p.id);
    const g = qs('#related'); g.innerHTML = rel.map(productCard).join('') || '<p class="hint">Explora el <a href="productos.html">catálogo completo</a>.</p>';
    bindAddButtons(g, rel);
  } catch (e) {}
})();
