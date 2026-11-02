'use strict';
(async () => {
  const { esc, api, config, productCard, bindAddButtons, qs } = App;
  try {
    const { settings: s } = await config();
    if (s.banner_title) qs('#hero-t').textContent = s.banner_title;
    if (s.banner_subtitle) qs('#banner-sub').textContent = s.banner_subtitle;
    if (s.banner_cta) qs('#banner-cta').textContent = s.banner_cta;
    const b = qs('#banner-badge'); if (s.banner_badge) b.textContent = s.banner_badge; else b.hidden = true;
  } catch (e) { /* sin config */ }
  const grid = qs('#featured');
  try {
    let { products } = await api('products.php?featured=1&limit=8');
    if (!products.length) ({ products } = await api('products.php?limit=8'));
    grid.innerHTML = products.map(productCard).join('') || '<p>Pronto tendremos productos.</p>';
    bindAddButtons(grid, products);
  } catch (e) { grid.innerHTML = `<div class="alert error">No se pudo cargar el catálogo: ${esc(e.message)}</div>`; }
})();
