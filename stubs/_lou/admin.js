/* Lou admin: confirmations, busy buttons, copy buttons, local times, the mobile menu, and the parent side of the inline editor. */
(function () {
  'use strict';
  var body = document.body;
  var csrf = body.dataset.csrf;
  var self = body.dataset.self;

  // Translations for the admin language (I18n::JS in PHP); English is the key and the fallback.
  var strings = {};
  try { strings = JSON.parse(body.dataset.i18n || '{}'); } catch (e) { strings = {}; }
  function t(text, vars) {
    var s = strings[text] || text;
    return s.replace(/\{([a-z_]+)\}/g, function (m, k) { return vars && k in vars ? vars[k] : m; });
  }

  // A confirm on a single button (forms with several actions, such as a redirect row).
  document.querySelectorAll('button[data-confirm]').forEach(function (b) {
    b.addEventListener('click', function (e) { if (!window.confirm(b.dataset.confirm)) e.preventDefault(); });
  });

  // Confirm, then mark the form busy: publishing, backups and updates take a few seconds, and a second
  // click would send the request again. The button is disabled a tick later, after the browser has read
  // its name/value into the request.
  document.querySelectorAll('form').forEach(function (f) {
    f.addEventListener('submit', function (e) {
      if (f.dataset.confirm && !window.confirm(f.dataset.confirm)) { e.preventDefault(); return; }
      if (e.defaultPrevented || f.method.toLowerCase() !== 'post' || f.hasAttribute('data-no-busy')) return;
      var button = e.submitter || f.querySelector('button[type=submit]');
      if (!button || button.disabled) return;
      setTimeout(function () {
        button.dataset.label = button.textContent;
        button.textContent = t('Working…');
        button.disabled = true;
        f.setAttribute('aria-busy', 'true');
      }, 0);
    });
  });
  // Coming back with the browser's Back button can show the page as it was left: undo the busy state.
  window.addEventListener('pageshow', function (e) {
    if (!e.persisted) return;
    document.querySelectorAll('button[data-label]').forEach(function (b) {
      b.textContent = b.dataset.label; b.disabled = false; delete b.dataset.label;
      b.form && b.form.removeAttribute('aria-busy');
    });
  });

  // Copy buttons: data-copy="<id>" copies that element's value (inputs) or its text, exactly as shown.
  function copyText(text) {
    if (navigator.clipboard && window.isSecureContext) return navigator.clipboard.writeText(text);
    // Plain http (no Clipboard API): copy through a temporary, off-screen text area.
    return new Promise(function (resolve, reject) {
      var t = document.createElement('textarea');
      t.value = text;
      t.setAttribute('readonly', '');
      t.className = 'sr';
      body.appendChild(t);
      t.select();
      var ok = false;
      try { ok = document.execCommand('copy'); } catch (err) { ok = false; }
      body.removeChild(t);
      ok ? resolve() : reject(new Error('copy failed'));
    });
  }
  document.addEventListener('click', function (e) {
    var b = e.target.closest ? e.target.closest('[data-copy]') : null;
    if (!b) return;
    var src = document.getElementById(b.dataset.copy);
    if (!src) return;
    var label = b.dataset.copyLabel || b.textContent;
    b.dataset.copyLabel = label;
    copyText('value' in src && src.tagName !== 'BUTTON' ? src.value : src.textContent).then(function () {
      b.textContent = t('Copied');
      b.classList.add('is-done');
    }, function () {
      b.textContent = t('Press Ctrl+C');
      if (src.select) src.select();
    }).then(function () {
      clearTimeout(b._t);
      b._t = setTimeout(function () { b.textContent = label; b.classList.remove('is-done'); }, 1600);
    });
  });

  // Accordions: "Expand all / Collapse all" for a group of <details>, and open the one a #link points at.
  function syncFoldButtons() {
    document.querySelectorAll('[data-fold-all]').forEach(function (b) {
      var box = document.querySelector(b.dataset.foldAll);
      if (!box) return;
      var closed = box.querySelectorAll('details:not([open])').length;
      b.textContent = closed ? t('Expand all') : t('Collapse all');
    });
  }
  document.querySelectorAll('[data-fold-all]').forEach(function (b) {
    b.addEventListener('click', function () {
      var box = document.querySelector(b.dataset.foldAll);
      if (!box) return;
      var open = box.querySelectorAll('details:not([open])').length > 0;
      box.querySelectorAll('details').forEach(function (d) { d.open = open; });
      syncFoldButtons();
    });
  });
  document.addEventListener('toggle', syncFoldButtons, true); // toggle doesn't bubble, so listen while capturing
  syncFoldButtons();
  function openHash() {
    var target = location.hash.length > 1 ? document.getElementById(decodeURIComponent(location.hash.slice(1))) : null;
    for (var el = target; el; el = el.parentElement) if (el.tagName === 'DETAILS') el.open = true;
  }
  window.addEventListener('hashchange', openHash);
  openHash();

  // Times without a chosen time zone are rendered in UTC: show them in the browser's time zone, in the
  // user's date format (<body data-date data-time>, the PHP tokens Y m d H i g A). UTC stays in the tooltip.
  // Times with data-fixed were already rendered in the time zone picked under Profile.
  var pad = function (n) { return (n < 10 ? '0' : '') + n; };
  function format(d, pattern) {
    return pattern.replace(/[YmdHigA]/g, function (c) {
      switch (c) {
        case 'Y': return d.getFullYear();
        case 'm': return pad(d.getMonth() + 1);
        case 'd': return pad(d.getDate());
        case 'H': return pad(d.getHours());
        case 'i': return pad(d.getMinutes());
        case 'g': return d.getHours() % 12 || 12;
        default: return d.getHours() < 12 ? 'AM' : 'PM';
      }
    });
  }
  var datePattern = body.dataset.date || 'Y-m-d';
  var timePattern = body.dataset.time || 'H:i';
  document.querySelectorAll('time[datetime]:not([data-fixed])').forEach(function (el) {
    var d = new Date(el.getAttribute('datetime'));
    if (isNaN(d.getTime())) return;
    el.title = el.textContent;
    el.textContent = format(d, el.hasAttribute('data-date') ? datePattern : datePattern + ' ' + timePattern);
  });

  // small screens: the side menu opens from the top bar button
  var toggle = document.getElementById('menu-toggle');
  if (toggle) {
    toggle.addEventListener('click', function () {
      var open = body.classList.toggle('menu-open');
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && body.classList.contains('menu-open')) { body.classList.remove('menu-open'); toggle.setAttribute('aria-expanded', 'false'); toggle.focus(); }
    });
  }

  // SMTP settings: follow the usual port when the encryption changes (only if the port is still a default one)
  var secure = document.getElementById('smtp-secure');
  var port = document.getElementById('smtp-port');
  if (secure && port) {
    secure.addEventListener('change', function () {
      if (['', '25', '465', '587'].indexOf(port.value) !== -1) port.value = { tls: '587', ssl: '465', '': '25' }[secure.value];
    });
  }

  document.querySelectorAll('[data-select]').forEach(function (i) {
    i.addEventListener('focus', function () { i.select(); });
  });

  var editor = document.querySelector('.editor');
  if (!editor) return;
  var frame = document.getElementById('preview');
  var status = document.getElementById('status');
  var state = { page: editor.dataset.page, lang: editor.dataset.lang };

  function post(route, data) {
    var fd = data instanceof FormData ? data : new FormData();
    if (!(data instanceof FormData)) Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
    fd.append('page', state.page);
    fd.append('lang', state.lang);
    return fetch(self + '?r=' + route, {
      method: 'POST', body: fd, credentials: 'same-origin',
      headers: { 'Accept': 'application/json', 'X-Lou-CSRF': csrf }
    }).then(function (r) { return r.json().catch(function () { return { ok: false, message: t('Server error ({status})', { status: r.status }) }; }); });
  }

  function say(text, error) {
    status.textContent = text;
    status.className = error ? 'alert alert-error' : 'muted';
  }

  function reloadPreview(path) {
    var win = frame.contentWindow;
    var y = win ? win.scrollY : 0;
    var url = new URL(frame.src, location.href);
    if (path) {
      var prefix = url.pathname.match(/^\/_lou\/p\/[a-f0-9]{32}/)[0];
      url.pathname = prefix + path;
    } else if (win) {
      url = new URL(win.location.href);
    }
    url.searchParams.set('_', Date.now());
    frame.addEventListener('load', function once() {
      frame.removeEventListener('load', once);
      try { frame.contentWindow.scrollTo(0, y); } catch (e) { /* ignore */ }
    });
    frame.src = url.toString();
  }

  function done(res, path) {
    if (res.ok) {
      say(res.draft ? t('Saved to your draft.') : t('Saved.'));
      reloadPreview(path);
    } else {
      say(res.message || t('Saving failed'), true);
      reloadPreview();
    }
  }

  // page settings
  var metaForm = document.getElementById('meta-form');
  metaForm.addEventListener('submit', function (e) {
    e.preventDefault();
    say(t('Saving…'));
    post('meta', new FormData(metaForm)).then(function (res) { done(res, res.path); });
  });

  function loadMeta() {
    fetch(self + '?r=meta&page=' + encodeURIComponent(state.page) + '&lang=' + encodeURIComponent(state.lang), {
      headers: { Accept: 'application/json' }, credentials: 'same-origin'
    }).then(function (r) { return r.json(); }).then(function (m) {
      if (!m.ok) return;
      ['title', 'description', 'menu_label', 'slug'].forEach(function (k) {
        if (metaForm.elements[k]) metaForm.elements[k].value = m[k] || '';
      });
      if (metaForm.elements.slug) metaForm.elements.slug.closest('label').hidden = m.special;
      document.title = t('Edit: {title}', { title: m.title }) + ' · Lou';
      document.querySelectorAll('.langs a').forEach(function (a) {
        var u = new URL(a.href);
        u.searchParams.set('page', state.page);
        a.href = u.toString();
      });
      history.replaceState(null, '', self + '?r=edit&page=' + encodeURIComponent(state.page) + '&lang=' + encodeURIComponent(state.lang));
    });
  }

  // image dialog
  var dialog = document.getElementById('image-dialog');
  var imageForm = document.getElementById('image-form');
  var imageField = null;
  document.getElementById('image-cancel').addEventListener('click', function () { dialog.close(); });
  imageForm.addEventListener('submit', function (e) {
    e.preventDefault();
    var fd = new FormData(imageForm);
    fd.append('field', imageField);
    dialog.close();
    say(t('Uploading…'));
    post('image', fd).then(function (res) { done(res); imageForm.reset(); });
  });

  // messages from the preview (editor.js)
  window.addEventListener('message', function (e) {
    if (e.origin !== location.origin || e.source !== frame.contentWindow || !e.data || !e.data.lou) return;
    var m = e.data;
    if (m.lou === 'page') {
      if (m.page !== state.page || m.lang !== state.lang) {
        state.page = m.page; state.lang = m.lang;
        loadMeta();
      }
    } else if (m.lou === 'save') {
      say(t('Saving…'));
      post('save', { field: m.field, type: m.type, value: m.value }).then(function (res) { done(res); });
    } else if (m.lou === 'image') {
      imageField = m.field;
      document.getElementById('image-current').src = m.src || '';
      imageForm.elements.alt.value = m.alt || '';
      dialog.showModal();
    }
  });

  // shareable preview links (live: the viewer reloads by itself as you save)
  function share(live) {
    var path = '/';
    try {
      path = new URL(frame.contentWindow.location.href).pathname.replace(/^\/_lou\/p\/[a-f0-9]{32}/, '') || '/';
    } catch (e) { /* ignore */ }
    var box = document.getElementById('share-result');
    var field = document.getElementById('share-url');
    var note = document.getElementById('share-note');
    note.textContent = t('Creating the link…');
    post('share', live ? { live: '1', path: path } : { days: 7 }).then(function (res) {
      if (res.ok) {
        field.value = res.url;
        box.hidden = false;
        var valid = res.live ? t('Live viewer link, valid for a day') : t('Preview link, valid for {days} days', { days: res.days });
        note.textContent = valid + '.';
        copyText(res.url).then(function () { note.textContent = t('{text}, copied to the clipboard.', { text: valid }); }, function () {});
      } else {
        note.textContent = res.message || t('Failed');
      }
    });
  }
  document.getElementById('share').addEventListener('click', function () { share(false); });
  document.getElementById('share-live').addEventListener('click', function () { share(true); });
})();
