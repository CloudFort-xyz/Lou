/* Lou admin: confirmations, busy buttons, copy buttons, local times, the mobile menu, and the parent side of the inline editor. */
(function () {
  'use strict';
  var body = document.body;
  var csrf = body.dataset.csrf;
  var self = body.dataset.self;

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
        button.textContent = 'Working…';
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
      b.textContent = 'Copied';
      b.classList.add('is-done');
    }, function () {
      b.textContent = 'Press Ctrl+C';
      if (src.select) src.select();
    }).then(function () {
      clearTimeout(b._t);
      b._t = setTimeout(function () { b.textContent = label; b.classList.remove('is-done'); }, 1600);
    });
  });

  // Times are rendered in UTC; show them in the browser's time zone (UTC stays in the tooltip).
  var pad = function (n) { return (n < 10 ? '0' : '') + n; };
  document.querySelectorAll('time[datetime]').forEach(function (t) {
    var d = new Date(t.getAttribute('datetime'));
    if (isNaN(d.getTime())) return;
    var day = d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
    t.title = t.textContent;
    t.textContent = t.hasAttribute('data-date') ? day : day + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes());
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
    }).then(function (r) { return r.json().catch(function () { return { ok: false, message: 'Server error (' + r.status + ')' }; }); });
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
      say(res.draft ? 'Saved to your draft.' : 'Saved.');
      reloadPreview(path);
    } else {
      say(res.message || 'Saving failed', true);
      reloadPreview();
    }
  }

  // page settings
  var metaForm = document.getElementById('meta-form');
  metaForm.addEventListener('submit', function (e) {
    e.preventDefault();
    say('Saving…');
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
      document.title = 'Edit: ' + m.title + ' · Lou';
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
    say('Uploading…');
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
      say('Saving…');
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
    note.textContent = 'Creating the link…';
    post('share', live ? { live: '1', path: path } : { days: 7 }).then(function (res) {
      if (res.ok) {
        field.value = res.url;
        box.hidden = false;
        var valid = res.live ? 'Live viewer link, valid for a day' : 'Preview link, valid for ' + res.days + ' days';
        note.textContent = valid + '.';
        copyText(res.url).then(function () { note.textContent = valid + ', copied to the clipboard.'; }, function () {});
      } else {
        note.textContent = res.message || 'Failed';
      }
    });
  }
  document.getElementById('share').addEventListener('click', function () { share(false); });
  document.getElementById('share-live').addEventListener('click', function () { share(true); });
})();
