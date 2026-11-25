/* Lou forms: progressive enhancement for forms marked with data-lou-form.
   Without JS the forms still work as plain POSTs. */
(function () {
  'use strict';
  var endpoint = '/_lou/submit.php';

  function fetchToken(form) {
    if (form.dataset.louToken) return;
    form.dataset.louToken = 'pending';
    fetch(endpoint + '?token=' + encodeURIComponent(form.elements.form.value), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) { if (d && d.token) { form.elements._token.value = d.token; form.dataset.louToken = 'ok'; } })
      .catch(function () { delete form.dataset.louToken; });
  }

  function clearErrors(form) {
    form.querySelectorAll('[data-lou-error]').forEach(function (el) { el.remove(); });
    form.querySelectorAll('[aria-invalid]').forEach(function (el) { el.removeAttribute('aria-invalid'); });
  }

  function showErrors(form, errors) {
    Object.keys(errors || {}).forEach(function (name) {
      var field = form.elements[name];
      if (!field || !field.insertAdjacentElement) return;
      field.setAttribute('aria-invalid', 'true');
      var msg = document.createElement('span');
      msg.className = 'lou-error';
      msg.setAttribute('data-lou-error', '');
      msg.textContent = errors[name];
      (field.closest('label') || field).insertAdjacentElement('afterend', msg);
    });
    var first = form.querySelector('[aria-invalid]');
    if (first) first.focus();
  }

  function status(form, text, ok) {
    var el = form.querySelector('[data-lou-status]');
    if (!el) return;
    el.textContent = text;
    el.className = 'lou-status ' + (ok ? 'lou-status--ok' : 'lou-status--error');
  }

  function recaptchaToken(form) {
    var key = form.dataset.louRecaptcha;
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
      return fetch(endpoint, { method: 'POST', body: new FormData(form), headers: { Accept: 'application/json' }, credentials: 'same-origin' });
    }).then(function (r) {
      return r.json().catch(function () { return { ok: false }; });
    }).then(function (d) {
      if (d.ok) {
        form.reset();
        delete form.dataset.louToken;
        if (window.turnstile) window.turnstile.reset();
      } else {
        showErrors(form, d.errors);
      }
      status(form, d.message || '', !!d.ok);
    }).catch(function () {
      status(form, form.dataset.louOffline || 'Network error', false);
    }).then(function () {
      if (button) button.disabled = false;
    });
  }

  document.querySelectorAll('form[data-lou-form]').forEach(function (form) {
    form.addEventListener('focusin', function () { fetchToken(form); }, { once: true });
    form.addEventListener('pointerdown', function () { fetchToken(form); }, { once: true });
    form.addEventListener('submit', submit);
  });
})();
