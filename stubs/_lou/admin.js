/* Lou admin: confirmations, the mobile menu, and the parent side of the inline editor. */
(function () {
  'use strict';
  var body = document.body;
  var csrf = body.dataset.csrf;
  var self = body.dataset.self;

  document.querySelectorAll('form[data-confirm]').forEach(function (f) {
    f.addEventListener('submit', function (e) { if (!window.confirm(f.dataset.confirm)) e.preventDefault(); });
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

  // undo / redo (server side, per page; see EditHistory.php)
  var undoBtn = document.getElementById('undo');
  var redoBtn = document.getElementById('redo');
  function setHistory(h) {
    if (!h) return;
    [[undoBtn, h.undo, 'Undo'], [redoBtn, h.redo, 'Redo']].forEach(function (b) {
      b[0].disabled = !b[1];
      b[0].title = b[1] ? b[2] + ': ' + b[1] : 'Nothing to ' + b[2].toLowerCase();
    });
  }
  setHistory({ undo: undoBtn.dataset.label || null, redo: redoBtn.dataset.label || null });
  function undoRedo(which) {
    var btn = which === 'undo' ? undoBtn : redoBtn;
    if (btn.disabled) return;
    btn.disabled = true;
    say(which === 'undo' ? 'Undoing…' : 'Redoing…');
    post(which, {}).then(function (res) {
      if (res.ok) {
        done(res);
        say((which === 'undo' ? 'Undone: ' : 'Redone: ') + res.done.label + (res.done.lang !== state.lang ? ' (' + res.done.lang.toUpperCase() + ')' : '') + '.');
      } else {
        say(res.message || 'Failed', true);
        refreshHistory();
      }
    });
  }
  undoBtn.addEventListener('click', function () { undoRedo('undo'); });
  redoBtn.addEventListener('click', function () { undoRedo('redo'); });
  function shortcut(e) {
    if (!(e.ctrlKey || e.metaKey) || e.altKey) return null;
    var k = e.key.toLowerCase();
    if (k === 'z') return e.shiftKey ? 'redo' : 'undo';
    if (k === 'y' && !e.shiftKey) return 'redo';
    return null;
  }
  document.addEventListener('keydown', function (e) {
    var t = e.target;
    if (t && (t.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName))) return; // let fields undo their own typing
    var which = shortcut(e);
    if (which) { e.preventDefault(); undoRedo(which); }
  });

  // text being typed is kept in localStorage by editor.js until the server has it
  function unsavedKey(field) { return 'lou:unsaved:' + state.page + ':' + state.lang + ':' + field; }
  function forget(field) { try { localStorage.removeItem(unsavedKey(field)); } catch (e) { /* storage blocked */ } }

  function reloadPreview(path, flash) {
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
      if (flash) frame.contentWindow.postMessage({ lou: 'flash', item: flash }, location.origin);
    });
    frame.src = url.toString();
  }

  function done(res, path, flash) {
    if (res.ok) {
      say(res.draft ? 'Saved to your draft.' : 'Saved.');
      setHistory(res.history);
      reloadPreview(path, flash);
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

  function refreshHistory() { loadMeta(); }

  function loadMeta() {
    fetch(self + '?r=meta&page=' + encodeURIComponent(state.page) + '&lang=' + encodeURIComponent(state.lang), {
      headers: { Accept: 'application/json' }, credentials: 'same-origin'
    }).then(function (r) { return r.json(); }).then(function (m) {
      if (!m.ok) return;
      ['title', 'description', 'menu_label', 'slug'].forEach(function (k) {
        if (metaForm.elements[k]) metaForm.elements[k].value = m[k] || '';
      });
      if (metaForm.elements.slug) metaForm.elements.slug.closest('label').hidden = m.special;
      setHistory(m.history);
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
      post('save', { field: m.field, type: m.type, value: m.value }).then(function (res) {
        if (res.ok) forget(m.field);
        done(res);
      });
    } else if (m.lou === 'item') {
      say(m.action === 'delete' ? 'Deleting…' : m.action === 'duplicate' ? 'Duplicating…' : 'Moving…');
      post('item', { item: m.item, action: m.action }).then(function (res) {
        done(res, null, res.ok && res.action !== 'delete' ? res.item : null);
        if (res.ok) {
          var what = { duplicate: 'Duplicated', delete: 'Deleted', up: 'Moved', down: 'Moved' }[res.action];
          say(what + '. ' + (res.skipped.length ? 'Not changed in ' + res.skipped.join(', ').toUpperCase() + ', because that list is different there.' : 'Saved to your draft.'));
        }
      });
    } else if (m.lou === 'key') {
      undoRedo(m.action);
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
