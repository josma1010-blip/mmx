'use strict';
(async () => {
  const { esc, api, money, qs, config } = App;
  const u = new URLSearchParams(location.search);
  const n = u.get('n') || '', t = u.get('t') || '';
  const main = qs('#o-main'), es = qs('#o-esims'), sum = qs('#o-summary');
  let o;
  async function load() {
    try { o = (await api(`order.php?n=${encodeURIComponent(n)}&t=${encodeURIComponent(t)}`)).order; }
    catch (e) { qs('#o-sub').textContent = ''; main.innerHTML = `<div class="empty"><div class="ic">🔒</div><h2>Pedido no encontrado</h2><p>Revisa el enlace que recibiste por correo.</p><a class="btn btn-primary" href="contacto.html">Contactar soporte</a></div>`; return; }
    render();
  }
  function addr(a) {
    if (!a) return '';
    if (typeof a === 'string') { try { a = JSON.parse(a); } catch { return esc(a); } }
    return esc([`${a.street || ''} ${a.ext_number || ''}${a.int_number ? ' int. ' + a.int_number : ''}`, a.neighborhood, `${a.zip || ''} ${a.city || ''}, ${a.state || ''}`, a.references].filter((x) => x && x.trim()).join(', '));
  }
  function render() {
    qs('#o-title').textContent = 'Pedido ' + o.order_number;
    qs('#o-sub').textContent = o.paid ? '¡Gracias por tu compra, ' + o.customer_name + '!' : 'Pedido registrado. Falta confirmar el pago.';
    const pillCls = o.paid ? 'ok' : (o.status === 'cancelado' ? 'no' : 'warn');
    let h = `<div class="alert ${o.paid ? 'success' : 'info'}"><b>Estado:</b> <span class="pill ${pillCls}">${esc(o.status_label)}</span> · Guarda esta página: es tu comprobante y acceso a tu pedido.</div>`;
    if (o.status === 'pendiente_pago') {
      if (o.payment_method === 'manual') h += `<h2>Instrucciones de pago</h2><div class="alert warn" style="white-space:pre-line">${esc(o.manual_instructions || '')}</div><p>Usa como referencia: <b>${esc(o.order_number)}</b>. Monto exacto: <b>${money(o.total)}</b>.</p>`;
      if (o.payment_method === 'paypal') h += `<h2>Paga con PayPal</h2><div id="paypal-buttons"></div><p class="hint" id="pp-msg"></p>`;
      if (o.payment_method === 'simulado') h += `<h2>Pago simulado</h2><div class="alert warn">Modo SOLO de desarrollo. No se cobra dinero real.</div><button class="btn btn-red" id="sim-pay">Simular pago aprobado</button>`;
    }
    if (o.status === 'cancelado') h += '<div class="alert error">Este pedido fue cancelado. Si ya realizaste un pago, contáctanos con tu número de pedido.</div>';
    if (o.paid && o.has_esim && !o.esims.length) h += '<div class="alert info">Estamos preparando tu eSIM. La recibirás por correo y aparecerá aquí en breve.</div>';
    if (o.has_physical) h += `<h2>Envío</h2><p>${addr(o.shipping_address)}</p>${o.tracking_number ? `<p>Guía de rastreo: <b>${esc(o.tracking_number)}</b></p>` : ''}`;
    main.innerHTML = h;
    if (o.esims.length) {
      es.hidden = false;
      es.innerHTML = '<h2>Tus eSIM</h2><p class="hint">Escanea el QR desde Ajustes › Red celular › Agregar eSIM. No compartas estos datos: cada eSIM se instala una sola vez.</p>' + o.esims.map((c) => `<div class="esim-card">
        <img src="${esc(c.qr_url)}" alt="Código QR de activación eSIM" width="200" height="200">
        <div><h3>${esc(c.product)}</h3><dl>
          <dt>ICCID</dt><dd>${esc(c.iccid)}</dd>
          <dt>Código de activación</dt><dd><code>${esc(c.activation_code)}</code></dd>
          ${c.smdp ? `<dt>SM-DP+</dt><dd><code>${esc(c.smdp)}</code></dd>` : ''}
          ${c.confirmation_code ? `<dt>Código de confirmación</dt><dd>${esc(c.confirmation_code)}</dd>` : ''}
          ${c.phone_number ? `<dt>Número</dt><dd>${esc(c.phone_number)}</dd>` : ''}</dl></div></div>`).join('');
    } else es.hidden = true;
    sum.innerHTML = '<h2>Resumen</h2>' + o.items.map((i) => `<div class="sum-row"><span>${i.quantity} × ${esc(i.name)}</span><span>${money(i.line_total)}</span></div>`).join('') +
      `<div class="sum-row"><span>Subtotal</span><span>${money(o.subtotal)}</span></div><div class="sum-row"><span>Envío</span><span>${o.has_physical ? money(o.shipping) : 'No aplica'}</span></div><div class="sum-row total"><span>Total</span><span>${money(o.total)} ${esc(o.currency)}</span></div>
      <p class="hint">Fecha: ${esc(o.created_at)}<br>Correo: ${esc(o.customer_email)}</p>`;
    if (o.status === 'pendiente_pago' && o.payment_method === 'paypal') paypal();
    const sb = qs('#sim-pay');
    if (sb) sb.addEventListener('click', async () => { sb.disabled = true; try { await api('dev_pay.php', { method: 'POST', body: { n, t } }); await load(); } catch (e) { App.toast(e.message); sb.disabled = false; } });
  }
  async function paypal() {
    const msg = qs('#pp-msg');
    const { settings: s } = await config();
    if (!s.paypal_client_id) { msg.textContent = 'PayPal no está disponible en este momento.'; return; }
    if (!window.paypal) {
      await new Promise((res, rej) => { const sc = document.createElement('script'); sc.src = 'https://www.paypal.com/sdk/js?client-id=' + encodeURIComponent(s.paypal_client_id) + '&currency=MXN&intent=capture&locale=es_MX'; sc.onload = res; sc.onerror = rej; document.head.appendChild(sc); })
        .catch(() => { msg.textContent = 'No se pudo cargar PayPal. Revisa tu conexión o bloqueador de anuncios.'; });
    }
    if (!window.paypal) return;
    window.paypal.Buttons({
      createOrder: async () => (await api('paypal_create.php', { method: 'POST', body: { n, t } })).id,
      onApprove: async (data) => {
        msg.textContent = 'Confirmando pago…';
        try { await api('paypal_capture.php', { method: 'POST', body: { n, t, paypal_order_id: data.orderID } }); await load(); }
        catch (e) { msg.textContent = 'No pudimos confirmar el pago: ' + e.message; }
      },
      onError: (err) => { msg.textContent = 'Ocurrió un error con PayPal. Intenta de nuevo.'; console.error(err); },
    }).render('#paypal-buttons');
  }
  load();
})();
