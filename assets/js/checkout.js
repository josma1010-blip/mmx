'use strict';
(async () => {
  const { esc, api, money, qs, qsa, cart, config, setFieldErrors } = App;
  const form = qs('#checkout-form'), alertBox = qs('#co-alert'), btn = qs('#place-order');
  const STATES = ['Aguascalientes','Baja California','Baja California Sur','Campeche','Chiapas','Chihuahua','Ciudad de México','Coahuila','Colima','Durango','Estado de México','Guanajuato','Guerrero','Hidalgo','Jalisco','Michoacán','Morelos','Nayarit','Nuevo León','Oaxaca','Puebla','Querétaro','Quintana Roo','San Luis Potosí','Sinaloa','Sonora','Tabasco','Tamaulipas','Tlaxcala','Veracruz','Yucatán','Zacatecas'];
  qs('#state').insertAdjacentHTML('beforeend', STATES.map((s) => `<option>${s}</option>`).join(''));
  const items = cart.get();
  if (!items.length) { location.href = 'carrito.html'; return; }
  let quote, cfg;
  try {
    [cfg, quote] = await Promise.all([config(), api('cart.php', { method: 'POST', body: { items: items.map((i) => ({ id: i.id, qty: i.qty })) } })]);
  } catch (e) { alertBox.innerHTML = `<div class="alert error">${esc(e.message)}</div>`; btn.disabled = true; return; }
  if (quote.errors.length) { location.href = 'carrito.html'; return; }
  qs('#co-items').innerHTML = quote.lines.map((l) => `<div class="sum-row"><span>${l.quantity} × ${esc(l.name)}</span><span>${money(l.line_total)}</span></div>`).join('');
  qs('#c-sub').textContent = money(quote.subtotal);
  qs('#c-ship').textContent = quote.has_physical ? (quote.shipping ? money(quote.shipping) : 'Gratis') : 'No aplica';
  qs('#c-total').textContent = money(quote.total);
  const needAddr = quote.has_physical;
  qs('#address-block').hidden = !needAddr;
  qs('#pay-num').textContent = needAddr ? '3' : '2';
  ['street', 'ext_number', 'neighborhood', 'zip', 'city', 'state'].forEach((n) => { form.elements[n].required = needAddr; });

  const labels = {
    paypal: ['PayPal / tarjeta', 'Paga con tu cuenta PayPal o tarjeta de débito/crédito. Confirmación inmediata.'],
    manual: ['Transferencia o depósito', 'Te mostraremos los datos bancarios. Tu pedido se libera cuando confirmemos el pago.'],
    simulado: ['Pago simulado (SOLO DESARROLLO)', 'Modo de pruebas activado en config.php. Desactívalo en producción.'],
  };
  const methods = cfg.settings.payment_methods || [];
  qs('#pay-methods').innerHTML = methods.length ? methods.map((m, i) => `<label class="pay-option"><input type="radio" name="payment_method" value="${m}" ${i ? '' : 'checked'}><span><b>${labels[m][0]}</b><small>${labels[m][1]}</small></span></label>`).join('')
    : '<div class="alert error">No hay métodos de pago disponibles. Contacta a la tienda.</div>';
  if (!methods.length) btn.disabled = true;

  function validate() {
    const f = form.elements, errs = {};
    if (f.name.value.trim().length < 3) errs.name = 'Escribe tu nombre completo';
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(f.email.value.trim())) errs.email = 'Correo electrónico inválido';
    const ph = f.phone.value.replace(/\D/g, ''); if (ph.length < 10 || ph.length > 13) errs.phone = 'Teléfono a 10 dígitos';
    if (needAddr) {
      [['street', 'Calle'], ['ext_number', 'Número exterior'], ['neighborhood', 'Colonia'], ['city', 'Ciudad'], ['state', 'Estado']].forEach(([k, l]) => { if (!f[k].value.trim()) errs[k] = `${l} es obligatorio`; });
      if (!/^\d{5}$/.test(f.zip.value.trim())) errs.zip = 'Código postal de 5 dígitos';
    }
    return errs;
  }
  form.addEventListener('submit', async (e) => {
    e.preventDefault(); alertBox.innerHTML = '';
    const errs = validate();
    setFieldErrors(form, errs);
    if (Object.keys(errs).length) return;
    if (!qs('#terms').checked) { alertBox.innerHTML = '<div class="alert warn">Debes aceptar las condiciones para continuar.</div>'; qs('#terms').focus(); return; }
    const pm = form.querySelector('input[name=payment_method]:checked'); if (!pm) return;
    const body = { items: cart.get().map((i) => ({ id: i.id, qty: i.qty })), payment_method: pm.value };
    ['name', 'email', 'phone', 'notes'].concat(needAddr ? ['street', 'ext_number', 'int_number', 'neighborhood', 'zip', 'city', 'state', 'references'] : []).forEach((k) => { body[k] = form.elements[k].value.trim(); });
    btn.disabled = true; btn.textContent = 'Procesando…';
    try {
      const r = await api('checkout.php', { method: 'POST', body });
      cart.clear();
      location.href = r.url;
    } catch (err) {
      btn.disabled = false; btn.textContent = 'Confirmar pedido';
      if (err.data && err.data.fields) setFieldErrors(form, err.data.fields);
      alertBox.innerHTML = `<div class="alert error" role="alert">${esc(err.message)}</div>`;
      alertBox.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
  });
})();
