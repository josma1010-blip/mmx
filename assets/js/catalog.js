'use strict';
(async () => {
  const { esc, api, config, productCard, qs, qsa, cart } = App;
  const form = qs('#filter-form'), grid = qs('#grid'), q = qs('#q'), sort = qs('#sort'), pag = qs('#pagination');
  let products = [], page = 1;
  try {
    const c = await config();
    qs('#f-category').insertAdjacentHTML('beforeend', c.categories.map((x) => `<option value="${esc(x.slug)}">${esc(x.name)}</option>`).join(''));
    qs('#f-company').insertAdjacentHTML('beforeend', c.companies.map((x) => `<option value="${x.id}">${esc(x.name)}</option>`).join(''));
  } catch (e) {}
  // Estado inicial desde la URL
  const u = new URLSearchParams(location.search);
  if (u.get('type')) { const r = form.querySelector(`input[name=type][value="${CSS.escape(u.get('type'))}"]`); if (r) r.checked = true; }
  ['category', 'company', 'min', 'max'].forEach((k) => { if (u.get(k)) form.elements[k].value = u.get(k); });
  if (u.get('available')) form.elements.available.checked = true;
  if (u.get('q')) q.value = u.get('q');
  if (u.get('sort')) sort.value = u.get('sort');
  page = Math.max(1, parseInt(u.get('page') || '1', 10));

  function params() {
    const p = new URLSearchParams();
    const fd = new FormData(form);
    for (const [k, v] of fd) if (v !== '') p.set(k, v);
    if (q.value.trim()) p.set('q', q.value.trim());
    if (sort.value) p.set('sort', sort.value);
    if (page > 1) p.set('page', page);
    return p;
  }
  async function load(push = true) {
    const p = params();
    if (push) history.replaceState(null, '', 'productos.html' + (p.toString() ? '?' + p : ''));
    grid.setAttribute('aria-busy', 'true');
    try {
      const d = await api('products.php?' + p);
      products = d.products;
      qs('#result-count').textContent = d.total === 1 ? '1 producto encontrado' : `${d.total} productos encontrados`;
      grid.innerHTML = products.length ? products.map(productCard).join('') : '<div class="empty" style="grid-column:1/-1"><div class="ic">🔎</div><h2>Sin resultados</h2><p>Prueba con otros filtros.</p></div>';
      pag.innerHTML = d.pages > 1 ? Array.from({ length: d.pages }, (_, i) => `<button class="${i + 1 === d.page ? 'active' : ''}" data-page="${i + 1}" aria-label="Página ${i + 1}" ${i + 1 === d.page ? 'aria-current="page"' : ''}>${i + 1}</button>`).join('') : '';
    } catch (e) { grid.innerHTML = `<div class="alert error">${esc(e.message)}</div>`; }
    grid.removeAttribute('aria-busy');
  }
  grid.addEventListener('click', (e) => { const b = e.target.closest('[data-add]'); if (!b) return; const p = products.find((x) => x.id === Number(b.dataset.add)); if (p) cart.add(p, 1); });
  pag.addEventListener('click', (e) => { const b = e.target.closest('[data-page]'); if (!b) return; page = +b.dataset.page; load(); window.scrollTo({ top: 0, behavior: 'smooth' }); });
  form.addEventListener('submit', (e) => { e.preventDefault(); page = 1; load(); form.classList.remove('open'); });
  form.addEventListener('change', () => { page = 1; load(); });
  form.addEventListener('reset', () => setTimeout(() => { q.value = ''; page = 1; load(); }, 0));
  sort.addEventListener('change', () => { page = 1; load(); });
  let t; q.addEventListener('input', () => { clearTimeout(t); t = setTimeout(() => { page = 1; load(); }, 300); });
  const ft = qs('.filter-toggle'), fl = qs('#filters');
  ft.addEventListener('click', () => { const o = fl.classList.toggle('open'); ft.setAttribute('aria-expanded', o); });
  load(false);
})();
