'use strict';
(() => {
  const { esc, api, qs, setFieldErrors } = App;
  const form = qs('#contact-form'), box = qs('#ct-alert');
  form.addEventListener('submit', async (e) => {
    e.preventDefault(); box.innerHTML = '';
    const f = form.elements, errs = {};
    if (f.name.value.trim().length < 2) errs.name = 'Escribe tu nombre';
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(f.email.value.trim())) errs.email = 'Correo inválido';
    if (f.message.value.trim().length < 10) errs.message = 'El mensaje debe tener al menos 10 caracteres';
    setFieldErrors(form, errs); if (Object.keys(errs).length) return;
    const b = form.querySelector('button[type=submit]'); b.disabled = true;
    try {
      const body = {}; ['name', 'email', 'phone', 'subject', 'message', 'website'].forEach((k) => { body[k] = f[k].value.trim(); });
      const r = await api('contact.php', { method: 'POST', body });
      form.reset(); box.innerHTML = `<div class="alert success" role="status">${esc(r.message || '¡Mensaje enviado!')}</div>`;
    } catch (err) {
      if (err.data && err.data.fields) setFieldErrors(form, err.data.fields);
      box.innerHTML = `<div class="alert error" role="alert">${esc(err.message)}</div>`;
    }
    b.disabled = false;
  });
})();
