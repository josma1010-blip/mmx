/* Panel de administración – JavaScript puro, consume /api/admin/* */
'use strict';
(() => {
  const $ = (s, el = document) => el.querySelector(s);
  const $$ = (s, el = document) => [...el.querySelectorAll(s)];
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const money = (n) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(Number(n) || 0);
  const img = (p) => (typeof p === 'string' && /^(assets|uploads)\/[A-Za-z0-9_\-./]+$/.test(p) && !p.includes('..')) ? '../' + p : '../assets/img/placeholder.svg';
  const STATUS = { pendiente_pago: 'Pendiente de pago', pagado: 'Pagado', en_proceso: 'En proceso', enviado: 'Enviado', entregado: 'Entregado', cancelado: 'Cancelado', reembolsado: 'Reembolsado' };
  const pill = (s, label) => `<span class="pill ${esc(s)}">${esc(label || STATUS[s] || s)}</span>`;
  let CSRF = '', ME = null;
  const view = $('#view');

  async function api(path, { method = 'GET', body, form } = {}) {
    const o = { method, credentials: 'same-origin', headers: { Accept: 'application/json' } };
    if (method !== 'GET') o.headers['X-CSRF-Token'] = CSRF;
    if (form) o.body = form; else if (body !== undefined) { o.headers['Content-Type'] = 'application/json'; o.body = JSON.stringify(body); }
    const r = await fetch('../api/admin/' + path, o);
    let d; try { d = await r.json(); } catch { d = { ok: false, error: 'Respuesta inválida (' + r.status + ')' }; }
    if (r.status === 401 && path !== 'login.php') { showLogin('Tu sesión expiró. Vuelve a entrar.'); throw new Error('Sesión expirada'); }
    if (r.status === 403 && d.must_change_password) { showPw(true); throw new Error(d.error); }
    if (!r.ok || !d.ok) throw Object.assign(new Error(d.error || 'Error'), { data: d });
    return d;
  }
  let tt; function toast(m) { const t = $('#toast'); t.textContent = m; t.classList.add('show'); clearTimeout(tt); tt = setTimeout(() => t.classList.remove('show'), 3500); }
  const err = (e) => toast('⚠️ ' + e.message);
  function modal(html) { $('#modal-body').innerHTML = html; $('#modal').hidden = false; const f = $('#modal-body input, #modal-body select, #modal-body textarea, #modal-body button'); if (f) f.focus(); return $('#modal-body'); }
  function closeModal() { $('#modal').hidden = true; $('#modal-body').innerHTML = ''; }
  $('.modal-x').addEventListener('click', closeModal);
  $('#modal').addEventListener('click', (e) => { if (e.target.id === 'modal') closeModal(); });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !$('#modal').hidden) closeModal(); });
  const formData = (f) => Object.fromEntries(new FormData(f));

  // ---------------- Sesión ----------------
  function showLogin(msg) { $('#app').hidden = true; $('#pw-view').hidden = true; $('#login-view').hidden = false; $('#login-msg').innerHTML = msg ? `<div class="msg warn">${esc(msg)}</div>` : ''; $('#login-form [name=username]').focus(); }
  function showPw(forced) { $('#pw-view').hidden = false; $('#login-view').hidden = true; $('#app').hidden = !!forced || $('#app').hidden; $('#pw-cancel').hidden = !!forced; $('#pw-why').textContent = forced ? 'Por seguridad debes reemplazar la contraseña temporal antes de continuar.' : 'Elige una contraseña nueva y robusta.'; }
  async function boot() {
    const r = await fetch('../api/admin/me.php', { credentials: 'same-origin' }).then((x) => x.json());
    CSRF = r.csrf;
    if (!r.logged) return showLogin();
    ME = r; if (r.must_change_password) return showPw(true);
    startApp();
  }
  $('#login-form').addEventListener('submit', async (e) => {
    e.preventDefault(); const b = e.target.querySelector('button'); b.disabled = true;
    try {
      const d = await api('login.php', { method: 'POST', body: formData(e.target) });
      CSRF = d.csrf; ME = d; e.target.reset();
      if (d.must_change_password) showPw(true); else startApp();
    } catch (x) { $('#login-msg').innerHTML = `<div class="msg err">${esc(x.message)}</div>`; }
    b.disabled = false;
  });
  $('#pw-form').addEventListener('submit', async (e) => {
    e.preventDefault(); const d = formData(e.target);
    if (d.new !== d.confirm) { $('#pw-msg').innerHTML = '<div class="msg err">Las contraseñas no coinciden</div>'; return; }
    try { await api('change_password.php', { method: 'POST', body: { current: d.current, new: d.new } }); e.target.reset(); $('#pw-msg').innerHTML = ''; toast('Contraseña actualizada'); ME.must_change_password = false; startApp(); }
    catch (x) { $('#pw-msg').innerHTML = `<div class="msg err">${esc(x.message)}</div>`; }
  });
  $('#pw-cancel').addEventListener('click', () => { $('#pw-view').hidden = true; $('#app').hidden = false; });
  $('#btn-pw').addEventListener('click', () => showPw(false));
  $('#btn-logout').addEventListener('click', async () => { try { await api('logout.php', { method: 'POST' }); } catch {} location.reload(); });

  function startApp() {
    $('#login-view').hidden = true; $('#pw-view').hidden = true; $('#app').hidden = false;
    $('#who').textContent = '👤 ' + (ME.username || ''); if (ME.store_name) $('#store-name').textContent = ME.store_name;
    route();
  }
  const TABS = { dashboard, orders, products, esim, customers, messages, settings, paypal, audit };
  function route() {
    const t = (location.hash || '#dashboard').slice(1).split('/')[0];
    const fn = TABS[t] || dashboard;
    $$('#tabs a').forEach((a) => a.classList.toggle('active', a.dataset.tab === t));
    view.innerHTML = '<p class="muted">Cargando…</p>';
    fn().catch((e) => { view.innerHTML = `<div class="msg err">${esc(e.message)}</div>`; });
  }
  window.addEventListener('hashchange', () => { if (!$('#app').hidden) route(); });

  // ---------------- Dashboard ----------------
  function lineChart(series) {
    const W = 760, H = 240, P = 40; const max = Math.max(1, ...series.map((s) => s.total));
    const x = (i) => P + (i * (W - P * 2)) / Math.max(1, series.length - 1), y = (v) => H - P - (v / max) * (H - P * 2);
    const pts = series.map((s, i) => `${x(i).toFixed(1)},${y(s.total).toFixed(1)}`).join(' ');
    const ticks = [0, .5, 1].map((f) => `<line x1="${P}" x2="${W - P}" y1="${y(max * f)}" y2="${y(max * f)}" stroke="#e5ebf3"/><text x="${P - 6}" y="${y(max * f) + 4}" font-size="10" text-anchor="end" fill="#5A6B85">${Math.round(max * f)}</text>`).join('');
    const labels = series.map((s, i) => (i % Math.ceil(series.length / 8) === 0 ? `<text x="${x(i)}" y="${H - P + 16}" font-size="10" text-anchor="middle" fill="#5A6B85">${s.date.slice(5)}</text>` : '')).join('');
    const dots = series.map((s, i) => s.total ? `<circle cx="${x(i)}" cy="${y(s.total)}" r="3.5" fill="#E4002B"><title>${s.date}: ${money(s.total)} (${s.orders} pedidos)</title></circle>` : '').join('');
    return `<svg class="chart" viewBox="0 0 ${W} ${H}" role="img" aria-label="Ventas por día">${ticks}<polygon points="${P},${H - P} ${pts} ${W - P},${H - P}" fill="rgba(47,164,231,.15)"/><polyline points="${pts}" fill="none" stroke="#0A4EA3" stroke-width="2.5"/>${dots}${labels}</svg>`;
  }
  function donut(by) {
    const cols = { pendiente_pago: '#F5B301', pagado: '#0A4EA3', en_proceso: '#2FA4E7', enviado: '#6C8EBF', entregado: '#0E9F6E', cancelado: '#E4002B', reembolsado: '#9B2C3F' };
    const ent = Object.entries(by); const tot = ent.reduce((a, [, n]) => a + +n, 0);
    if (!tot) return '<p class="muted">Sin pedidos todavía.</p>';
    let a0 = -Math.PI / 2, paths = '';
    ent.forEach(([k, n]) => { const a1 = a0 + (n / tot) * Math.PI * 2; const big = a1 - a0 > Math.PI ? 1 : 0;
      const p = (a) => `${(80 + 70 * Math.cos(a)).toFixed(2)},${(80 + 70 * Math.sin(a)).toFixed(2)}`;
      paths += n == tot ? `<circle cx="80" cy="80" r="70" fill="${cols[k] || '#999'}"/>` : `<path d="M80,80 L${p(a0)} A70,70 0 ${big} 1 ${p(a1)} Z" fill="${cols[k] || '#999'}"><title>${STATUS[k]}: ${n}</title></path>`; a0 = a1; });
    return `<svg viewBox="0 0 160 160" width="160" height="160" role="img" aria-label="Pedidos por estado">${paths}<circle cx="80" cy="80" r="40" fill="#fff"/><text x="80" y="86" text-anchor="middle" font-size="20" font-weight="700" fill="#062F66">${tot}</text></svg>
      <div>${ent.map(([k, n]) => `<div class="small"><span style="display:inline-block;width:10px;height:10px;border-radius:3px;background:${cols[k]}"></span> ${STATUS[k] || k}: <b>${n}</b></div>`).join('')}</div>`;
  }
  async function dashboard() {
    const d = await api('dashboard.php?days=30'); const k = d.kpis;
    const u = $('#unread'); u.hidden = !k.unread_messages; u.textContent = k.unread_messages;
    const maxTop = Math.max(1, ...d.top_products.map((t) => +t.total));
    view.innerHTML = `<h1>Dashboard</h1>
    <div class="kpis">
      <div class="kpi"><span>Ventas totales</span><b>${money(k.revenue)}</b></div>
      <div class="kpi"><span>Ventas últimos 30 días</span><b>${money(k.revenue_period)}</b></div>
      <div class="kpi"><span>Hoy</span><b>${money(k.revenue_today)}</b></div>
      <div class="kpi"><span>Pedidos pagados</span><b>${k.orders_paid}</b></div>
      <div class="kpi"><span>Ticket promedio</span><b>${money(k.avg_ticket)}</b></div>
      <div class="kpi ${k.pending_payment ? 'warn' : ''}"><span>Pendientes de pago</span><b>${k.pending_payment}</b></div>
      <div class="kpi ${k.pending_esim ? 'alert' : ''}"><span>eSIM por entregar</span><b>${k.pending_esim}</b></div>
      <div class="kpi"><span>Clientes</span><b>${k.customers}</b></div>
    </div>
    <div class="grid2"><div class="card"><h2 style="margin-top:0">Ventas por día (30 días)</h2>${lineChart(d.series)}</div>
    <div class="card"><h2 style="margin-top:0">Pedidos por estado</h2><div style="display:flex;gap:1rem;align-items:center;flex-wrap:wrap">${donut(d.by_status)}</div></div></div>
    <div class="grid2" style="margin-top:1rem"><div class="card"><h2 style="margin-top:0">Productos más vendidos</h2>${d.top_products.map((t) => `<div class="bar-row"><span>${esc(t.name)}</span><div class="bar" style="width:${(t.total / maxTop) * 100}%"></div><span class="num">${money(t.total)}</span></div>`).join('') || '<p class="muted">Sin ventas aún.</p>'}</div>
    <div class="card"><h2 style="margin-top:0">Inventario bajo</h2>${d.low_stock.map((p) => `<div class="small" style="display:flex;justify-content:space-between;padding:.2rem 0"><span>${esc(p.name)} <span class="muted">(${p.type === 'esim' ? 'códigos' : 'piezas'})</span></span><b style="color:${p.stock ? '#7a5200' : '#E4002B'}">${p.stock}</b></div>`).join('') || '<p class="muted">Todo en orden.</p>'}</div></div>
    <h2>Pedidos recientes</h2>${ordersTable(d.recent)}`;
  }

  // ---------------- Pedidos ----------------
  function ordersTable(rows) {
    if (!rows.length) return '<p class="muted">No hay pedidos.</p>';
    return `<div class="tbl-wrap"><table><thead><tr><th>Pedido</th><th>Cliente</th><th>Fecha</th><th>Estado</th><th class="num">Total</th><th></th></tr></thead><tbody>
      ${rows.map((o) => `<tr><td><b>${esc(o.order_number)}</b>${o.has_esim == 1 && o.esim_delivered == 0 && ['pagado', 'en_proceso'].includes(o.status) ? ' <span class="pill reservado">eSIM pendiente</span>' : ''}</td><td>${esc(o.customer_name)}<div class="muted small">${esc(o.customer_email || '')}</div></td><td class="small">${esc(o.created_at)}</td><td>${pill(o.status)}</td><td class="num">${money(o.total)}</td><td><a class="btn sm" href="#orders/${o.id}">Ver</a></td></tr>`).join('')}</tbody></table></div>`;
  }
  async function orders() {
    const id = location.hash.split('/')[1]; if (id) return orderDetail(+id);
    view.innerHTML = `<h1>Pedidos</h1><div class="toolbar"><input type="search" id="o-q" placeholder="Buscar número, nombre o correo" aria-label="Buscar"><select id="o-st" aria-label="Estado"><option value="">Todos los estados</option>${Object.entries(STATUS).map(([k, v]) => `<option value="${k}">${v}</option>`).join('')}</select></div><div id="o-list"></div>`;
    const load = async () => { const d = await api(`orders.php?q=${encodeURIComponent($('#o-q').value)}&status=${$('#o-st').value}`); $('#o-list').innerHTML = ordersTable(d.orders) + `<p class="muted small">${d.total} pedido(s)</p>`; };
    let t; $('#o-q').addEventListener('input', () => { clearTimeout(t); t = setTimeout(() => load().catch(err), 300); });
    $('#o-st').addEventListener('change', () => load().catch(err));
    await load();
  }
  async function orderDetail(id) {
    const { order: o } = await api('orders.php?id=' + id);
    let addr = ''; try { const a = typeof o.shipping_address === 'string' ? JSON.parse(o.shipping_address) : o.shipping_address; if (a) addr = [`${a.street} ${a.ext_number}${a.int_number ? ' int. ' + a.int_number : ''}`, a.neighborhood, `CP ${a.zip}`, `${a.city}, ${a.state}`, a.references].filter(Boolean).join(', '); } catch { addr = o.shipping_address || ''; }
    const paid = ['pagado', 'en_proceso', 'enviado', 'entregado'].includes(o.status);
    const esimNeed = o.items.filter((i) => i.type === 'esim').reduce((a, i) => a + +i.quantity, 0);
    const delivered = o.esims.filter((e) => e.status === 'entregado').length;
    view.innerHTML = `<p><a href="#orders">← Pedidos</a></p><h1>Pedido ${esc(o.order_number)} ${pill(o.status)}</h1>
    <div class="grid2"><div>
      <div class="card"><h2 style="margin-top:0">Productos</h2><div class="tbl-wrap"><table><thead><tr><th>Producto</th><th>Tipo</th><th class="num">Precio</th><th class="num">Cant.</th><th class="num">Total</th></tr></thead><tbody>
      ${o.items.map((i) => `<tr><td>${esc(i.product_name)}</td><td>${i.type === 'esim' ? 'eSIM' : 'Físico'}</td><td class="num">${money(i.unit_price)}</td><td class="num">${i.quantity}</td><td class="num">${money(i.line_total)}</td></tr>`).join('')}
      <tr><td colspan="4" class="num">Envío</td><td class="num">${money(o.shipping)}</td></tr><tr><td colspan="4" class="num"><b>Total</b></td><td class="num"><b>${money(o.total)} ${esc(o.currency)}</b></td></tr></tbody></table></div></div>
      ${esimNeed ? `<div class="card" style="margin-top:1rem"><h2 style="margin-top:0">eSIM (${delivered}/${esimNeed} entregadas)</h2>
        ${o.esims.length ? `<div class="tbl-wrap"><table><thead><tr><th>ICCID</th><th>Código</th><th>Estado</th><th>Entregada</th><th></th></tr></thead><tbody>${o.esims.map((e) => `<tr><td><code>${esc(e.iccid)}</code></td><td><code>${esc(e.activation_code)}</code></td><td>${pill(e.status)}</td><td class="small">${esc(e.delivered_at || '—')}</td><td><a class="btn sm" target="_blank" rel="noopener" href="../api/admin/esim.php?qr=${e.id}">QR</a></td></tr>`).join('')}</tbody></table></div>` : '<p class="muted">Sin códigos asignados.</p>'}
        <p class="muted small">Los códigos reservados no se muestran al cliente hasta confirmar el pago.</p></div>` : ''}
      <div class="card" style="margin-top:1rem"><h2 style="margin-top:0">Pagos</h2>${o.payments.length ? o.payments.map((p) => `<div class="small">${esc(p.created_at)} · <b>${esc(p.method)}</b> · ${money(p.amount)} ${esc(p.currency)} · ref <code>${esc(p.provider_ref)}</code> · ${esc(p.status)} · por ${esc(p.created_by || '')}</div>`).join('') : '<p class="muted">Sin pagos registrados.</p>'}</div>
      <div class="card" style="margin-top:1rem"><details><summary>Historial / auditoría (${o.log.length})</summary>${o.log.map((l) => `<div class="small">${esc(l.created_at)} · <b>${esc(l.actor)}</b> · ${esc(l.action)} <span class="muted">${esc(l.details || '')}</span></div>`).join('')}</details></div>
    </div><div>
      <div class="card"><h2 style="margin-top:0">Acciones</h2><div class="actions" style="flex-direction:column;align-items:stretch">
        ${o.status === 'pendiente_pago' ? `<button class="btn green" data-act="mark_paid">✔ Marcar como pagado</button>` : ''}
        ${paid && esimNeed && delivered < esimNeed ? `<button class="btn primary" data-act="deliver">📲 Entregar eSIM ahora</button>` : ''}
        ${delivered ? `<button class="btn" data-act="resend">✉️ Reenviar eSIM por correo</button>` : ''}
        ${paid ? `<label>Cambiar estado<select id="st-new">${['en_proceso', 'enviado', 'entregado', 'reembolsado'].map((s) => `<option value="${s}" ${s === o.status ? 'selected' : ''}>${STATUS[s]}</option>`).join('')}</select></label><button class="btn" data-act="status">Guardar estado</button>` : ''}
        ${!['cancelado', 'reembolsado', 'entregado', 'enviado'].includes(o.status) ? `<button class="btn red" data-act="cancel">Cancelar pedido</button>` : ''}
      </div></div>
      <div class="card" style="margin-top:1rem"><h2 style="margin-top:0">Cliente</h2><p><b>${esc(o.customer_name)}</b><br><a href="mailto:${esc(o.customer_email)}">${esc(o.customer_email)}</a><br>${esc(o.customer_phone || '')}</p>
        ${addr ? `<p class="small"><b>Envío:</b> ${esc(addr)}</p>` : ''}<p class="small"><b>Método:</b> ${esc(o.payment_method)} · <b>Creado:</b> ${esc(o.created_at)}${o.paid_at ? ` · <b>Pagado:</b> ${esc(o.paid_at)}` : ''}</p>
        ${o.notes ? `<p class="small"><b>Nota del cliente:</b> ${esc(o.notes)}</p>` : ''}
        <label>Enlace privado del cliente<input readonly value="${esc(o.customer_link)}" onclick="this.select()"></label></div>
      <form class="card" style="margin-top:1rem" id="notes-f"><label>Guía de rastreo<input name="tracking" value="${esc(o.tracking_number || '')}" maxlength="100"></label><label style="margin-top:.6rem">Notas internas<textarea name="admin_notes" rows="3">${esc(o.admin_notes || '')}</textarea></label><button class="btn sm" style="margin-top:.6rem">Guardar notas</button></form>
    </div></div>`;
    view.addEventListener('click', async function h(e) {
      const b = e.target.closest('[data-act]'); if (!b) return;
      const act = b.dataset.act; const body = { id, action: act };
      if (act === 'mark_paid') { const ref = prompt(`Confirma que recibiste ${money(o.total)}.\nReferencia del pago (opcional):`, ''); if (ref === null) return; body.reference = ref; }
      if (act === 'cancel') { const r = prompt('Motivo de la cancelación (se liberará el inventario reservado):', ''); if (r === null) return; body.reason = r; }
      if (act === 'status') { body.status = $('#st-new').value; if (body.status === 'enviado') body.tracking = $('#notes-f [name=tracking]').value; }
      b.disabled = true;
      try { const r = await api('orders.php', { method: 'POST', body }); toast(r.message || 'Listo'); view.removeEventListener('click', h); orderDetail(id); }
      catch (x) { err(x); b.disabled = false; }
    });
    $('#notes-f').addEventListener('submit', async (e) => { e.preventDefault(); try { await api('orders.php', { method: 'POST', body: { id, action: 'notes', ...formData(e.target) } }); toast('Notas guardadas'); } catch (x) { err(x); } });
  }

  // ---------------- Productos ----------------
  let META = { categories: [], companies: [] };
  async function products() {
    const d = await api('products.php'); META = d;
    view.innerHTML = `<h1>Productos</h1><div class="toolbar"><button class="btn primary" id="p-new">+ Nuevo producto</button><button class="btn ghost" id="p-cat">+ Categoría</button><button class="btn ghost" id="p-comp">+ Compañía</button><input type="search" id="p-q" placeholder="Filtrar…" aria-label="Filtrar productos"></div>
    <div class="tbl-wrap"><table><thead><tr><th></th><th>Producto</th><th>Tipo</th><th>Compañía</th><th class="num">Precio</th><th class="num">Inventario</th><th>Activo</th><th></th></tr></thead><tbody id="p-body">
    ${d.products.map((p) => `<tr data-id="${p.id}" data-name="${esc((p.name + ' ' + p.company + ' ' + p.category).toLowerCase())}"><td><img class="thumb" src="${esc(img(p.image))}" alt=""></td><td><b>${esc(p.name)}</b>${p.is_example == 1 ? ' <span class="pill reservado">EJEMPLO</span>' : ''}${p.featured == 1 ? ' ⭐' : ''}<div class="muted small">${esc(p.category)}</div></td><td>${p.type === 'esim' ? 'eSIM' : 'Físico'}</td><td>${esc(p.company)}</td>
      <td class="num"><input type="number" step="0.01" min="0.01" value="${p.price}" data-quick="price" style="width:100px" aria-label="Precio"></td>
      <td class="num">${p.type === 'fisico' ? `<input type="number" min="0" value="${p.stock}" data-quick="stock" style="width:80px" aria-label="Stock">` : `<a href="#esim" title="Códigos disponibles">${p.inventory} códigos</a>`}</td>
      <td><input type="checkbox" data-quick="active" ${p.active == 1 ? 'checked' : ''} aria-label="Activo"></td>
      <td class="actions"><button class="btn sm" data-edit>Editar</button><button class="btn sm red" data-del>Eliminar</button></td></tr>`).join('')}</tbody></table></div>
    <p class="muted small">Inventario eSIM = códigos disponibles (se agregan en “Códigos eSIM”). Inventario físico = piezas en stock.</p>`;
    $('#p-new').onclick = () => productForm(null);
    $('#p-cat').onclick = async () => { const n = prompt('Nombre de la nueva categoría:'); if (n) try { await api('products.php', { method: 'POST', body: { action: 'category', name: n } }); toast('Categoría creada'); products(); } catch (x) { err(x); } };
    $('#p-comp').onclick = async () => { const n = prompt('Nombre de la nueva compañía:'); if (n) try { await api('products.php', { method: 'POST', body: { action: 'company', name: n } }); toast('Compañía creada'); products(); } catch (x) { err(x); } };
    $('#p-q').oninput = (e) => { const q = e.target.value.toLowerCase(); $$('#p-body tr').forEach((tr) => { tr.hidden = !tr.dataset.name.includes(q); }); };
    $('#p-body').addEventListener('change', async (e) => {
      const f = e.target.dataset.quick; if (!f) return; const id = +e.target.closest('tr').dataset.id;
      const v = f === 'active' ? e.target.checked : e.target.value;
      try { await api('products.php', { method: 'POST', body: { action: 'quick', id, [f]: v } }); toast('Guardado'); } catch (x) { err(x); }
    });
    $('#p-body').addEventListener('click', async (e) => {
      const tr = e.target.closest('tr'); if (!tr) return; const id = +tr.dataset.id;
      if (e.target.closest('[data-edit]')) productForm(id);
      if (e.target.closest('[data-del]') && confirm('¿Eliminar este producto? Si tiene pedidos o códigos se desactivará en su lugar.')) {
        try { const r = await api('products.php', { method: 'POST', body: { action: 'delete', id } }); toast(r.message || 'Producto eliminado'); products(); } catch (x) { err(x); }
      }
    });
  }
  async function productForm(id) {
    const p = id ? (await api('products.php?id=' + id)).product : { type: 'esim', active: 1, available: 1, price: '', stock: 0, images: [] };
    const opt = (list, sel) => list.map((x) => `<option value="${x.id}" ${+sel === +x.id ? 'selected' : ''}>${esc(x.name)}</option>`).join('');
    const m = modal(`<h2 style="margin-top:0">${id ? 'Editar' : 'Nuevo'} producto</h2><div id="pf-msg"></div>
    <form id="pf" class="form-grid">
      <label class="full">Nombre *<input name="name" required minlength="3" maxlength="180" value="${esc(p.name || '')}"></label>
      <label>Slug (URL)<input name="slug" maxlength="200" value="${esc(p.slug || '')}" placeholder="se genera solo"></label>
      <label>Tipo *<select name="type"><option value="esim" ${p.type === 'esim' ? 'selected' : ''}>eSIM (inventario = códigos)</option><option value="fisico" ${p.type === 'fisico' ? 'selected' : ''}>Chip físico (inventario = stock)</option></select></label>
      <label>Categoría *<select name="category_id" required>${opt(META.categories, p.category_id)}</select></label>
      <label>Compañía *<select name="company_id" required>${opt(META.companies, p.company_id)}</select></label>
      <label>Precio MXN *<input name="price" type="number" step="0.01" min="0.01" required value="${esc(p.price)}"></label>
      <label>Precio anterior (tachado)<input name="compare_price" type="number" step="0.01" min="0" value="${esc(p.compare_price ?? '')}"></label>
      <label>Stock físico<input name="stock" type="number" min="0" value="${esc(p.stock)}"></label>
      <label class="full">Descripción corta<input name="short_description" maxlength="300" value="${esc(p.short_description || '')}"></label>
      <label class="full">Descripción<textarea name="description" rows="5">${esc(p.description || '')}</textarea></label>
      <div class="full" style="display:flex;gap:1.2rem;flex-wrap:wrap">
        <label class="inline"><input type="checkbox" name="active" ${p.active == 1 ? 'checked' : ''}> Activo (visible)</label>
        <label class="inline"><input type="checkbox" name="available" ${p.available == 1 ? 'checked' : ''}> Disponible para venta</label>
        <label class="inline"><input type="checkbox" name="featured" ${p.featured == 1 ? 'checked' : ''}> Destacado</label>
        <label class="inline"><input type="checkbox" name="is_example" ${p.is_example == 1 ? 'checked' : ''}> Producto de ejemplo</label></div>
      <div class="full"><button class="btn primary">Guardar</button></div>
    </form>
    ${id ? `<h2>Fotos</h2><p class="muted small">JPG, PNG o WEBP · máx. 3 MB. La primera es la principal.</p><div class="imgs" id="pf-imgs">${p.images.map((i, n) => `<figure data-img="${i.id}"><img src="${esc(img(i.path))}" alt=""><div class="actions" style="justify-content:center">${n ? '<button class="btn sm" data-primary>Principal</button>' : '<span class="pill pagado">Principal</span>'}<button class="btn sm red" data-imgdel>✕</button></div></figure>`).join('') || '<p class="muted">Sin fotos.</p>'}</div>
      <form id="pf-up" class="toolbar" style="margin-top:.6rem"><input type="file" name="image" accept="image/jpeg,image/png,image/webp" required aria-label="Imagen"><button class="btn">Subir foto</button></form>
      ${p.type === 'esim' ? `<p class="small">Códigos: ${Object.entries(p.esim_counts || {}).map(([k, v]) => `${k}: <b>${v}</b>`).join(' · ') || 'ninguno'} — <a href="#esim" onclick="document.getElementById('modal').hidden=true">administrar códigos</a></p>` : ''}` : '<p class="muted small">Guarda el producto para poder subir fotos.</p>'}`);
    $('#pf', m).addEventListener('submit', async (e) => {
      e.preventDefault(); const f = e.target, d = formData(f);
      ['active', 'available', 'featured', 'is_example'].forEach((k) => { d[k] = f.elements[k].checked ? 1 : 0; });
      try { const r = await api('products.php', { method: 'POST', body: { action: id ? 'update' : 'create', id, ...d } }); toast('Producto guardado'); products(); productForm(r.id); }
      catch (x) { $('#pf-msg').innerHTML = `<div class="msg err">${esc(x.message)}</div>`; }
    });
    if (!id) return;
    $('#pf-up', m).addEventListener('submit', async (e) => {
      e.preventDefault(); const fd = new FormData(e.target); fd.append('product_id', id); fd.append('action', 'upload');
      try { await api('images.php', { method: 'POST', form: fd }); toast('Foto subida'); productForm(id); products(); } catch (x) { err(x); }
    });
    $('#pf-imgs', m).addEventListener('click', async (e) => {
      const fig = e.target.closest('[data-img]'); if (!fig) return; const imgId = +fig.dataset.img;
      const action = e.target.closest('[data-imgdel]') ? 'delete' : e.target.closest('[data-primary]') ? 'primary' : null; if (!action) return;
      if (action === 'delete' && !confirm('¿Eliminar foto?')) return;
      try { await api('images.php', { method: 'POST', body: { action, id: imgId } }); productForm(id); products(); } catch (x) { err(x); }
    });
  }

  // ---------------- eSIM ----------------
  async function esim() {
    const d = await api('esim.php' + (esimFilter.toString() ? '?' + esimFilter : ''));
    const prods = d.summary;
    view.innerHTML = `<h1>Códigos eSIM</h1>
    <div class="tbl-wrap" style="margin-bottom:1rem"><table><thead><tr><th>Producto eSIM</th><th class="num">Disponibles</th><th class="num">Reservados</th><th class="num">Entregados</th><th class="num">Anulados</th></tr></thead><tbody>
      ${prods.map((s) => `<tr><td>${esc(s.name)}</td><td class="num"><b style="color:${+s.disponible ? '#0E9F6E' : '#E4002B'}">${+s.disponible || 0}</b></td><td class="num">${+s.reservado || 0}</td><td class="num">${+s.entregado || 0}</td><td class="num">${+s.anulado || 0}</td></tr>`).join('')}</tbody></table></div>
    <div class="grid2"><form class="card" id="e-add"><h2 style="margin-top:0">Agregar código individual</h2><div class="form-grid">
      <label>Producto *<select name="product_id" required>${prods.map((p) => `<option value="${p.id}">${esc(p.name)}</option>`).join('')}</select></label>
      <label>ICCID *<input name="iccid" required pattern="[0-9A-Za-z\\-]{6,40}" maxlength="40"></label>
      <label class="full">Código de activación (LPA) *<input name="activation_code" required minlength="8" maxlength="500" placeholder="LPA:1$smdp.ejemplo.com$CODIGO"></label>
      <label>SM-DP+<input name="smdp_address" maxlength="190"></label><label>Código de confirmación<input name="confirmation_code" maxlength="60"></label>
      <label>Número telefónico<input name="phone_number" maxlength="20"></label><label>QR (imagen opcional)<input type="file" name="qr" accept="image/png,image/jpeg,image/webp"></label>
      <label class="full">Notas<input name="notes" maxlength="255"></label></div><button class="btn primary" style="margin-top:.6rem">Agregar</button><div id="e-add-msg"></div></form>
    <form class="card" id="e-imp"><h2 style="margin-top:0">Importar CSV</h2>
      <p class="small muted">Columnas: <code>iccid,activation_code,smdp_address,confirmation_code,phone_number,notes</code> (encabezado opcional; separador “,” o “;”). Los duplicados se detectan y se omiten.</p>
      <label>Producto *<select name="product_id" required>${prods.map((p) => `<option value="${p.id}">${esc(p.name)}</option>`).join('')}</select></label>
      <label style="margin-top:.6rem">Archivo CSV<input type="file" name="csv" accept=".csv,text/csv,text/plain"></label>
      <label style="margin-top:.6rem">…o pega el contenido<textarea name="csv_text" rows="4" placeholder="iccid,activation_code"></textarea></label>
      <button class="btn primary" style="margin-top:.6rem">Importar</button><div id="e-imp-msg"></div></form></div>
    <h2>Códigos</h2><div class="toolbar"><select id="ef-p" aria-label="Producto"><option value="">Todos los productos</option>${prods.map((p) => `<option value="${p.id}" ${esimFilter.get('product_id') == p.id ? 'selected' : ''}>${esc(p.name)}</option>`).join('')}</select>
      <select id="ef-s" aria-label="Estado"><option value="">Todos</option>${['disponible', 'reservado', 'entregado', 'anulado'].map((s) => `<option ${esimFilter.get('status') === s ? 'selected' : ''}>${s}</option>`).join('')}</select><input type="search" id="ef-q" placeholder="ICCID o código" value="${esc(esimFilter.get('q') || '')}"><button class="btn" id="ef-go">Filtrar</button></div>
    <div class="tbl-wrap"><table><thead><tr><th>ID</th><th>Producto</th><th>ICCID</th><th>Código</th><th>Estado</th><th>Pedido</th><th></th></tr></thead><tbody id="e-body">
    ${d.codes.map((c) => `<tr data-id="${c.id}"><td>${c.id}</td><td class="small">${esc(c.product)}</td><td><code>${esc(c.iccid)}</code></td><td><code>${esc(c.activation_code.length > 40 ? c.activation_code.slice(0, 40) + '…' : c.activation_code)}</code></td><td>${pill(c.status)}</td><td class="small">${esc(c.order_number || '—')}</td>
      <td class="actions"><a class="btn sm" target="_blank" rel="noopener" href="../api/admin/esim.php?qr=${c.id}">QR</a>${c.status === 'disponible' ? '<button class="btn sm" data-a="void">Anular</button>' : ''}${c.status === 'anulado' && !c.order_number ? '<button class="btn sm" data-a="restore">Reactivar</button>' : ''}${['disponible', 'anulado'].includes(c.status) && !c.order_number ? '<button class="btn sm red" data-a="delete">Borrar</button>' : ''}</td></tr>`).join('') || '<tr><td colspan="7" class="muted">Sin códigos.</td></tr>'}</tbody></table></div>`;
    $('#e-add').addEventListener('submit', async (e) => {
      e.preventDefault(); const fd = new FormData(e.target); fd.append('action', 'add'); if (!e.target.qr.files.length) fd.delete('qr');
      try { await api('esim.php', { method: 'POST', form: fd }); toast('Código agregado'); esim(); } catch (x) { $('#e-add-msg').innerHTML = `<div class="msg err">${esc(x.message)}</div>`; }
    });
    $('#e-imp').addEventListener('submit', async (e) => {
      e.preventDefault(); const fd = new FormData(e.target); fd.append('action', 'import'); if (!e.target.csv.files.length) fd.delete('csv');
      try {
        const r = await api('esim.php', { method: 'POST', form: fd });
        const html = `<div class="msg ok">Insertados: <b>${r.inserted}</b></div>${r.duplicates.length ? `<div class="msg warn">Duplicados omitidos (${r.duplicates.length}):<br>${r.duplicates.map(esc).join('<br>')}</div>` : ''}${r.errors.length ? `<div class="msg err">Errores (${r.errors.length}):<br>${r.errors.map(esc).join('<br>')}</div>` : ''}`;
        await esim(); $('#e-imp-msg').innerHTML = html;
      } catch (x) { $('#e-imp-msg').innerHTML = `<div class="msg err">${esc(x.message)}</div>`; }
    });
    $('#ef-go').onclick = () => { esimFilter = new URLSearchParams(); if ($('#ef-p').value) esimFilter.set('product_id', $('#ef-p').value); if ($('#ef-s').value) esimFilter.set('status', $('#ef-s').value); if ($('#ef-q').value) esimFilter.set('q', $('#ef-q').value); esim().catch(err); };
    $('#e-body').addEventListener('click', async (e) => {
      const b = e.target.closest('[data-a]'); if (!b) return; const id = +b.closest('tr').dataset.id;
      if (b.dataset.a === 'delete' && !confirm('¿Borrar este código definitivamente?')) return;
      try { await api('esim.php', { method: 'POST', body: { action: b.dataset.a, id } }); toast('Listo'); esim(); } catch (x) { err(x); }
    });
  }
  let esimFilter = new URLSearchParams();

  // ---------------- Clientes / Mensajes / Auditoría ----------------
  async function customers() {
    view.innerHTML = '<h1>Clientes</h1><div class="toolbar"><input type="search" id="c-q" placeholder="Nombre, correo o teléfono"></div><div id="c-list"></div>';
    const load = async () => { const d = await api('customers.php?q=' + encodeURIComponent($('#c-q').value));
      $('#c-list').innerHTML = `<div class="tbl-wrap"><table><thead><tr><th>Nombre</th><th>Correo</th><th>Teléfono</th><th>Ciudad</th><th class="num">Pedidos</th><th class="num">Comprado</th><th>Alta</th></tr></thead><tbody>${d.customers.map((c) => `<tr><td>${esc(c.name)}</td><td><a href="mailto:${esc(c.email)}">${esc(c.email)}</a></td><td>${esc(c.phone || '')}</td><td>${esc([c.city, c.state].filter(Boolean).join(', '))}</td><td class="num">${c.orders}</td><td class="num">${money(c.spent)}</td><td class="small">${esc(c.created_at)}</td></tr>`).join('') || '<tr><td colspan="7" class="muted">Sin clientes.</td></tr>'}</tbody></table></div>`; };
    let t; $('#c-q').oninput = () => { clearTimeout(t); t = setTimeout(() => load().catch(err), 300); };
    await load();
  }
  async function messages() {
    const d = await api('messages.php');
    view.innerHTML = `<h1>Mensajes de contacto</h1>${d.messages.map((m) => `<div class="card" style="margin-bottom:.7rem;${m.is_read == 0 ? 'border-left:4px solid #E4002B' : ''}" data-id="${m.id}">
      <div style="display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap"><div><b>${esc(m.subject || '(sin asunto)')}</b> · ${esc(m.name)} &lt;<a href="mailto:${esc(m.email)}">${esc(m.email)}</a>&gt; ${esc(m.phone || '')}</div><span class="muted small">${esc(m.created_at)}</span></div>
      <p style="white-space:pre-line">${esc(m.message)}</p><div class="actions"><button class="btn sm" data-a="read" data-u="${m.is_read == 1 ? 1 : 0}">${m.is_read == 1 ? 'Marcar no leído' : 'Marcar leído'}</button><button class="btn sm red" data-a="delete">Eliminar</button></div></div>`).join('') || '<p class="muted">No hay mensajes.</p>'}`;
    view.onclick = async (e) => {
      const b = e.target.closest('[data-a]'); if (!b || !b.closest('[data-id]')) return; const id = +b.closest('[data-id]').dataset.id;
      if (b.dataset.a === 'delete' && !confirm('¿Eliminar mensaje?')) return;
      try { await api('messages.php', { method: 'POST', body: { action: b.dataset.a, id, unread: b.dataset.u === '1' } }); messages(); } catch (x) { err(x); }
    };
  }
  async function audit() {
    const d = await api('audit.php');
    view.innerHTML = `<h1>Bitácora de auditoría</h1><p class="muted small">Últimos 300 eventos: entregas de eSIM, pagos, cambios de productos, accesos.</p><div class="tbl-wrap"><table><thead><tr><th>Fecha</th><th>Actor</th><th>Acción</th><th>Entidad</th><th>Detalles</th><th>IP</th></tr></thead><tbody>${d.log.map((l) => `<tr><td class="small">${esc(l.created_at)}</td><td>${esc(l.actor)}</td><td><b>${esc(l.action)}</b></td><td class="small">${esc(l.entity || '')} ${esc(l.entity_id || '')}</td><td class="small"><code>${esc(l.details || '')}</code></td><td class="small">${esc(l.ip || '')}</td></tr>`).join('')}</tbody></table></div>`;
  }

  // ---------------- Configuración ----------------
  async function settings() {
    const { settings: s, dev_simulated_payment: dev } = await api('settings.php');
    const f = (k, label, type = 'text', extra = '') => `<label>${label}<input name="${k}" type="${type}" value="${esc(s[k])}" ${extra}></label>`;
    const ta = (k, label, rows = 3) => `<label class="full">${label}<textarea name="${k}" rows="${rows}">${esc(s[k])}</textarea></label>`;
    view.innerHTML = `<h1>Configuración de la tienda</h1>${dev ? '<div class="msg err"><b>Atención:</b> el pago SIMULADO está activado en config/config.php. Desactívalo (dev_simulated_payment = false) antes de vender.</div>' : ''}
    <form id="sf"><div class="card"><h2 style="margin-top:0">General</h2><div class="form-grid">${f('store_name', 'Nombre de la tienda *', 'text', 'required maxlength="120"')}${f('store_tagline', 'Eslogan')}${f('contact_email', 'Correo de contacto', 'email')}${f('contact_phone', 'Teléfono')}${f('contact_whatsapp', 'WhatsApp (solo dígitos, ej. 5215512345678)', 'text', 'pattern="\\d{0,15}"')}${f('contact_address', 'Dirección / ciudad')}${ta('about_text', 'Texto “Acerca de” (pie de página)', 2)}${ta('footer_disclaimer', 'Aviso legal del pie (distribuidor independiente)', 2)}</div></div>
    <div class="card" style="margin-top:1rem"><h2 style="margin-top:0">Banner promocional (inicio)</h2><div class="form-grid">${f('banner_badge', 'Etiqueta')}${f('banner_title', 'Título')}${f('banner_cta', 'Texto del botón')}${ta('banner_subtitle', 'Subtítulo', 2)}</div></div>
    <div class="card" style="margin-top:1rem"><h2 style="margin-top:0">Envío, inventario y eSIM</h2><div class="form-grid">${f('shipping_cost', 'Costo de envío (MXN)', 'number', 'step="0.01" min="0"')}${f('free_shipping_from', 'Envío gratis desde (MXN, 0 = nunca)', 'number', 'step="0.01" min="0"')}
      <label>Entrega de eSIM<select name="esim_delivery_mode"><option value="auto" ${s.esim_delivery_mode === 'auto' ? 'selected' : ''}>Automática al confirmar pago</option><option value="manual" ${s.esim_delivery_mode === 'manual' ? 'selected' : ''}>Manual (el admin entrega)</option></select></label>
      ${f('reservation_hours', 'Horas para pagar antes de cancelar', 'number', 'min="1"')}${f('low_stock_threshold', 'Alerta de inventario bajo', 'number', 'min="1"')}<input type="hidden" name="currency" value="MXN"></div></div>
    <div class="card" style="margin-top:1rem"><h2 style="margin-top:0">Pago manual / transferencia</h2><label class="inline"><input type="checkbox" name="manual_enabled" ${s.manual_enabled === '1' ? 'checked' : ''}> Habilitar transferencia/depósito</label>${ta('manual_payment_instructions', 'Instrucciones (banco, CLABE, titular…)', 5)}</div>
    <div class="card" style="margin-top:1rem"><h2 style="margin-top:0">Correo (mail())</h2><div class="form-grid">${f('mail_from', 'Remitente (From)', 'email')}${f('mail_from_name', 'Nombre del remitente')}${f('notify_email', 'Notificar pedidos/mensajes a', 'email')}</div><p class="muted small">El envío usa mail() de PHP; depende de la configuración de tu hosting.</p></div>
    <p><button class="btn primary">Guardar configuración</button></p></form>`;
    $('#sf').addEventListener('submit', async (e) => {
      e.preventDefault(); const d = formData(e.target); d.manual_enabled = e.target.manual_enabled.checked ? '1' : '0';
      try { await api('settings.php', { method: 'POST', body: { settings: d } }); toast('Configuración guardada'); $('#store-name').textContent = d.store_name; } catch (x) { err(x); }
    });
  }
  async function paypal() {
    const { paypal: p } = await api('paypal.php');
    view.innerHTML = `<h1>PayPal</h1><div class="msg info">Usa credenciales de una app REST de <a href="https://developer.paypal.com/dashboard/applications" target="_blank" rel="noopener">developer.paypal.com</a>. Prueba primero en <b>Sandbox</b>. El Secret se guarda cifrado y nunca se envía al navegador.</div>
    <form class="card" id="ppf"><div class="form-grid">
      <label class="inline full"><input type="checkbox" name="enabled" ${p.enabled ? 'checked' : ''}> Habilitar PayPal en el checkout</label>
      <label>Modo<select name="mode"><option value="sandbox" ${p.mode === 'sandbox' ? 'selected' : ''}>Sandbox (pruebas)</option><option value="live" ${p.mode === 'live' ? 'selected' : ''}>Live (producción)</option></select></label>
      <label>Client ID<input name="client_id" value="${esc(p.client_id)}" maxlength="200" autocomplete="off"></label>
      <label>Secret ${p.secret_set ? `<span class="muted small">(guardado: ${esc(p.secret_hint)})</span>` : '<span class="muted small">(no configurado)</span>'}<input name="secret" type="password" autocomplete="new-password" placeholder="${p.secret_set ? 'Dejar vacío para conservar' : 'Pega el secret'}"></label>
      <label>Webhook ID (opcional)<input name="webhook_id" value="${esc(p.webhook_id)}" maxlength="100"></label>
      <label class="full">URL del webhook (configúrala en PayPal con el evento PAYMENT.CAPTURE.COMPLETED)<input readonly value="${esc(p.webhook_url)}" onclick="this.select()"></label>
      ${p.secret_set ? '<label class="inline full"><input type="checkbox" name="clear_secret"> Borrar secret guardado</label>' : ''}
    </div><div class="actions" style="margin-top:.8rem"><button class="btn primary">Guardar</button><button class="btn" type="button" id="pp-test">Probar conexión</button></div><div id="pp-msg"></div></form>`;
    $('#ppf').addEventListener('submit', async (e) => {
      e.preventDefault(); const f = e.target, d = formData(f); d.enabled = f.enabled.checked; d.clear_secret = f.clear_secret ? f.clear_secret.checked : false;
      try { await api('paypal.php', { method: 'POST', body: d }); toast('PayPal guardado'); paypal(); } catch (x) { $('#pp-msg').innerHTML = `<div class="msg err">${esc(x.message)}</div>`; }
    });
    $('#pp-test').onclick = async () => { try { const r = await api('paypal.php', { method: 'POST', body: { action: 'test' } }); $('#pp-msg').innerHTML = `<div class="msg ok">${esc(r.message)}</div>`; } catch (x) { $('#pp-msg').innerHTML = `<div class="msg err">${esc(x.message)}</div>`; } };
  }

  boot().catch((e) => { document.body.insertAdjacentHTML('afterbegin', `<div class="msg err">No se pudo conectar con la API: ${esc(e.message)}</div>`); });
})();
