/* Lou admin: confirmations, and the parent side of the inline editor. */
(function () {
  'use strict';
  var body = document.body;
  var csrf = body.dataset.csrf;
  var self = body.dataset.self;

  document.querySelectorAll('form[data-confirm]').forEach(function (f) {
    f.addEventListener('submit', function (e) { if (!window.confirm(f.dataset.confirm)) e.preventDefault(); });
  });

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
    post('share', live ? { live: '1', path: path } : { days: 7 }).then(function (res) {
      var out = document.getElementById('share-result');
      if (res.ok) {
        out.textContent = res.url + (res.live ? ' (live, valid for a day)' : ' (valid for ' + res.days + ' days)');
        if (navigator.clipboard) navigator.clipboard.writeText(res.url).then(function () { out.textContent += ', copied'; }, function () {});
      } else {
        out.textContent = res.message || 'Failed';
      }
    });
  }
  document.getElementById('share').addEventListener('click', function () { share(false); });
  document.getElementById('share-live').addEventListener('click', function () { share(true); });
})();
