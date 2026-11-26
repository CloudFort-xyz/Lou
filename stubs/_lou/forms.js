/* Progressive enhancement for forms marked with data-form. Without JS they still work as plain POSTs. */
(function () {
  'use strict';
  function fetchToken(form) {
    if (form.dataset.token) return;
    form.dataset.token = 'pending';
    fetch(form.action + '?token=' + encodeURIComponent(form.elements.form.value), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) { if (d && d.token) { form.elements._token.value = d.token; form.dataset.token = 'ok'; } })
      .catch(function () { delete form.dataset.token; });
  }

  function clearErrors(form) {
    form.querySelectorAll('[data-error]').forEach(function (el) { el.remove(); });
    form.querySelectorAll('[aria-invalid]').forEach(function (el) { el.removeAttribute('aria-invalid'); });
  }

  function showErrors(form, errors) {
    Object.keys(errors || {}).forEach(function (name) {
      var field = form.elements[name];
      if (!field || !field.insertAdjacentElement) return;
      field.setAttribute('aria-invalid', 'true');
      var msg = document.createElement('span');
      msg.className = 'form-error';
      msg.setAttribute('data-error', '');
      msg.textContent = errors[name];
      (field.closest('label') || field).insertAdjacentElement('afterend', msg);
    });
    var first = form.querySelector('[aria-invalid]');
    if (first) first.focus();
  }

  function status(form, text, ok) {
    var el = form.querySelector('[data-status]');
    if (!el) {
      // the template has no status line: add one at the end of the form
      el = document.createElement('p');
      el.setAttribute('data-status', '');
      el.setAttribute('role', 'status');
      el.setAttribute('aria-live', 'polite');
      form.appendChild(el);
    }
    el.textContent = text;
    el.className = 'form-status ' + (ok ? 'form-status--ok' : 'form-status--error');
  }

  function recaptchaToken(form) {
    var key = form.dataset.recaptcha;
    if (!key || !window.grecaptcha) return Promise.resolve();
    return new Promise(function (resolve) {
      window.grecaptcha.ready(function () {
        window.grecaptcha.execute(key, { action: 'submit' }).then(function (token) {
          var input = form.elements['g-recaptcha-response'];
          if (input) input.value = token;
          resolve();
        }, resolve);
      });
    });
  }

  function submit(e) {
    var form = e.currentTarget;
    e.preventDefault();
    var button = form.querySelector('[type=submit]');
    if (button) button.disabled = true;
    clearErrors(form);
    recaptchaToken(form).then(function () {
      return fetch(form.action, { method: 'POST', body: new FormData(form), headers: { Accept: 'application/json' }, credentials: 'same-origin' });
    }).then(function (r) {
      return r.json().catch(function () { return { ok: false }; });
    }).then(function (d) {
      if (d.ok) {
        form.reset();
        delete form.dataset.token;
        if (window.turnstile) window.turnstile.reset();
      } else {
        showErrors(form, d.errors);
      }
      status(form, d.message || '', !!d.ok);
    }).catch(function () {
      status(form, form.dataset.offline || 'Network error', false);
    }).then(function () {
      if (button) button.disabled = false;
    });
  }

  document.querySelectorAll('form[data-form]').forEach(function (form) {
    form.addEventListener('focusin', function () { fetchToken(form); }, { once: true });
    form.addEventListener('pointerdown', function () { fetchToken(form); }, { once: true });
    form.addEventListener('submit', submit);
  });
})();
