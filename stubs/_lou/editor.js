/* Lou inline editor: runs inside the admin's preview iframe and talks to the admin via postMessage. */
(function () {
  'use strict';
  if (window.parent === window) return; // only inside the admin
  var me = document.currentScript;
  var page = me.dataset.page, lang = me.dataset.lang;
  var origin = location.origin;
  var send = function (msg) { msg.lou = msg.lou || 'save'; window.parent.postMessage(msg, origin); };

  send({ lou: 'page', page: page, lang: lang });

  // previews must never submit real forms
  document.addEventListener('submit', function (e) { e.preventDefault(); }, true);

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text) n.textContent = text;
    return n;
  }
  function button(text, title, onClick) {
    var b = el('button', '', text);
    b.type = 'button';
    if (title) { b.title = title; b.setAttribute('aria-label', title); }
    b.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); onClick(); });
    return b;
  }
  function typeOf(node) { return node.dataset.louType || 'text'; }
  function valueOf(node) { return typeOf(node) === 'html' ? node.innerHTML : node.innerText; }

  var bar = el('div', 'lou-editbar');
  bar.hidden = true;
  document.body.appendChild(bar);

  var mod = /Mac|iPhone|iPad/.test(navigator.platform || '') ? '⌘' : 'Ctrl+';

  // Bold/italic run through execCommand ourselves: browsers differ (Firefox's Ctrl+B opens the
  // bookmarks sidebar instead), and the B / I buttons make it visible for people who don't use shortcuts.
  function format(cmd) {
    try { document.execCommand('styleWithCSS', false, false); } catch (e) { /* not supported */ }
    document.execCommand(cmd, false, null);
  }

  function hint(text, rich) {
    bar.textContent = '';
    if (rich) {
      [['bold', 'B', 'Bold (' + mod + 'B)'], ['italic', 'I', 'Italic (' + mod + 'I)']].forEach(function (f) {
        var b = button(f[1], f[2], function () { format(f[0]); });
        b.className = 'lou-format lou-format-' + f[0];
        b.addEventListener('mousedown', function (e) { e.preventDefault(); }); // keep the text selection
        bar.appendChild(b);
      });
    }
    bar.appendChild(document.createTextNode(text));
    bar.hidden = !text;
  }

  // ------------------------------------------------------------ local backup of unsaved typing
  // The text being typed is kept in localStorage until the admin confirms the server saved it
  // (admin.js removes the entry). If the tab closes or the save fails, the next load asks what to keep.

  var prefix = 'lou:unsaved:' + page + ':' + lang + ':';
  var store = {
    get: function (k) { try { return JSON.parse(localStorage.getItem(k) || 'null'); } catch (e) { return null; } },
    set: function (k, v) { try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) { /* storage full or blocked */ } },
    del: function (k) { try { localStorage.removeItem(k); } catch (e) { /* ignore */ } },
    keys: function () {
      var out = [];
      try { for (var i = 0; i < localStorage.length; i++) { var k = localStorage.key(i); if (k && k.indexOf(prefix) === 0) out.push(k); } } catch (e) { /* ignore */ }
      return out;
    }
  };

  // ------------------------------------------------------------ text and image fields

  var active = null, original = null, originalText = '', backupTimer = 0;

  function backup() {
    if (!active) return;
    var value = valueOf(active);
    var key = prefix + active.dataset.louField;
    if (value.trim() === (typeOf(active) === 'html' ? original : originalText).trim()) { store.del(key); return; }
    store.set(key, { field: active.dataset.louField, type: typeOf(active), value: value,
      original: typeOf(active) === 'html' ? original : originalText, at: Date.now() });
  }

  function finish(save) {
    if (!active) return;
    var node = active, type = typeOf(node);
    var value = valueOf(node);
    clearTimeout(backupTimer);
    node.removeAttribute('contenteditable');
    active = null;
    hint('');
    if (!save) { node.innerHTML = original; store.del(prefix + node.dataset.louField); return; }
    if (value.trim() === (type === 'html' ? original : originalText).trim()) { store.del(prefix + node.dataset.louField); return; }
    store.set(prefix + node.dataset.louField, { field: node.dataset.louField, type: type, value: value,
      original: type === 'html' ? original : originalText, at: Date.now() });
    send({ field: node.dataset.louField, type: type, value: value });
    hint('Saving…');
  }

  document.addEventListener('click', function (e) {
    if (e.target.closest('.lou-itembar, .lou-restore, .lou-editbar')) return; // our own toolbars
    var node = e.target.closest('[data-lou-field]');
    if (!node) {
      if (active) {
        // first click outside only saves; it doesn't follow links
        e.preventDefault();
        e.stopPropagation();
        finish(true);
      }
      return;
    }
    if (node === active) return;
    e.preventDefault();
    e.stopPropagation();
    if (active) finish(true);
    hideItemBar();
    if (node.dataset.louType === 'image') {
      send({ lou: 'image', field: node.dataset.louField, src: node.currentSrc || node.src, alt: node.alt });
      return;
    }
    active = node;
    original = node.innerHTML;
    originalText = node.innerText;
    var plain = typeOf(node) === 'text';
    try { node.contentEditable = plain ? 'plaintext-only' : 'true'; } catch (err) { node.contentEditable = 'true'; }
    if (node.contentEditable !== 'plaintext-only' && node.contentEditable !== 'true') node.contentEditable = 'true';
    node.focus();
    hint(plain ? 'Editing text. Enter or click outside to save, Esc to cancel.'
      : 'Select text, then B or I (' + mod + 'B, ' + mod + 'I). Click outside to save, Esc to cancel.', !plain);
  }, true);

  document.addEventListener('input', function () {
    if (!active) return;
    clearTimeout(backupTimer);
    backupTimer = setTimeout(backup, 400);
  });

  document.addEventListener('keydown', function (e) {
    if (!active) {
      // Ctrl+Z / Ctrl+Shift+Z / Ctrl+Y outside a field: undo/redo saved changes (done by the admin)
      if ((e.ctrlKey || e.metaKey) && !e.altKey) {
        var k = e.key.toLowerCase();
        var action = k === 'z' ? (e.shiftKey ? 'redo' : 'undo') : (k === 'y' && !e.shiftKey ? 'redo' : '');
        if (action) { e.preventDefault(); send({ lou: 'key', action: action }); }
      }
      return;
    }
    if ((e.ctrlKey || e.metaKey) && !e.altKey && !e.shiftKey && /^[biu]$/i.test(e.key)) {
      e.preventDefault(); // never let the browser take these while editing (Firefox: Ctrl+B = bookmarks sidebar)
      if (typeOf(active) === 'html') format({ b: 'bold', i: 'italic', u: 'underline' }[e.key.toLowerCase()]);
      return;
    }
    if (e.key === 'Escape') { e.preventDefault(); finish(false); }
    else if (e.key === 'Enter' && typeOf(active) === 'text') { e.preventDefault(); finish(true); }
  }, true);

  // paste as plain text into plain fields
  document.addEventListener('paste', function (e) {
    if (!active || typeOf(active) !== 'text') return;
    e.preventDefault();
    var text = (e.clipboardData || window.clipboardData).getData('text/plain');
    document.execCommand('insertText', false, text.replace(/\s+/g, ' '));
  });

  // ------------------------------------------------------------ repeating items (data-lou-item)
  // A small toolbar on the item under the pointer: duplicate, move, delete. The admin does the work.

  var itemBar = el('div', 'lou-itembar');
  itemBar.hidden = true;
  itemBar.setAttribute('role', 'toolbar');
  document.body.appendChild(itemBar);
  var current = null, hideTimer = 0;

  function listOf(path) { return path.slice(0, path.lastIndexOf('.')); }
  function siblings(path) {
    var list = listOf(path);
    return Array.prototype.filter.call(document.querySelectorAll('[data-lou-item]'), function (n) {
      var p = n.dataset.louItem;
      return listOf(p) === list && /^\d+$/.test(p.slice(list.length + 1));
    });
  }

  function placeItemBar() {
    if (!current) return;
    var r = current.getBoundingClientRect();
    itemBar.style.top = Math.max(0, r.top + window.scrollY - itemBar.offsetHeight - 4) + 'px';
    itemBar.style.left = Math.max(0, r.right + window.scrollX - itemBar.offsetWidth) + 'px';
  }

  function act(action) {
    send({ lou: 'item', item: current.dataset.louItem, action: action });
    hideItemBar();
  }

  function fillItemBar(confirming) {
    itemBar.textContent = '';
    if (confirming) {
      itemBar.appendChild(el('span', '', 'Delete this?'));
      itemBar.appendChild(button('Delete', 'Delete this item', function () { act('delete'); }));
      itemBar.lastChild.className = 'lou-danger';
      itemBar.appendChild(button('Keep', 'Keep this item', function () { fillItemBar(false); }));
      itemBar.lastChild.focus();
      return;
    }
    var path = current.dataset.louItem;
    var sib = siblings(path);
    var i = sib.indexOf(current);
    var other = sib[i + 1] || sib[i - 1];
    var horizontal = false;
    if (other) {
      var a = current.getBoundingClientRect(), b = other.getBoundingClientRect();
      horizontal = Math.abs(a.top - b.top) < a.height / 2;
    }
    itemBar.appendChild(button('⧉ Duplicate', 'Duplicate', function () { act('duplicate'); }));
    var up = button(horizontal ? '←' : '↑', horizontal ? 'Move left' : 'Move up', function () { act('up'); });
    var down = button(horizontal ? '→' : '↓', horizontal ? 'Move right' : 'Move down', function () { act('down'); });
    up.disabled = i <= 0;
    down.disabled = i === -1 || i >= sib.length - 1;
    itemBar.appendChild(up);
    itemBar.appendChild(down);
    var del = button('🗑', 'Delete', function () { fillItemBar(true); });
    del.disabled = sib.length <= 1;
    if (del.disabled) del.title = 'The last item can\'t be deleted';
    itemBar.appendChild(del);
  }

  function showItemBar(node) {
    clearTimeout(hideTimer);
    if (active || node === current) return;
    if (current) current.classList.remove('lou-item-on');
    current = node;
    current.classList.add('lou-item-on');
    fillItemBar(false);
    itemBar.hidden = false;
    placeItemBar();
  }

  function hideItemBar() {
    clearTimeout(hideTimer);
    if (current) current.classList.remove('lou-item-on');
    current = null;
    itemBar.hidden = true;
  }

  function track(target) {
    if (itemBar.contains(target)) { clearTimeout(hideTimer); return; }
    var node = target.closest && target.closest('[data-lou-item]');
    if (node) showItemBar(node);
    else if (current) { clearTimeout(hideTimer); hideTimer = setTimeout(hideItemBar, 300); }
  }
  document.addEventListener('mouseover', function (e) { track(e.target); });
  document.addEventListener('focusin', function (e) { track(e.target); });
  window.addEventListener('resize', placeItemBar);

  // the admin points at an item after a change (e.g. the new copy)
  window.addEventListener('message', function (e) {
    if (e.origin !== origin || e.source !== window.parent || !e.data || e.data.lou !== 'flash') return;
    var node = document.querySelector('[data-lou-item="' + CSS.escape(String(e.data.item)) + '"]');
    if (!node) return;
    var r = node.getBoundingClientRect();
    if (r.top < 0 || r.bottom > window.innerHeight) node.scrollIntoView({ block: 'center' });
    node.classList.add('lou-flash');
    setTimeout(function () { node.classList.remove('lou-flash'); }, 1600);
  });

  // ------------------------------------------------------------ restore unsaved typing after a reload

  function askRestore() {
    var keys = store.keys();
    for (var i = 0; i < keys.length; i++) {
      var saved = store.get(keys[i]);
      var node = saved && document.querySelector('[data-lou-field="' + CSS.escape(String(saved.field)) + '"]');
      if (!node || typeof saved.value !== 'string' || saved.value.trim() === valueOf(node).trim()) {
        store.del(keys[i]); // already saved, or the field is gone
        continue;
      }
      showRestore(keys[i], saved, node);
      return;
    }
  }

  function showRestore(key, saved, node) {
    var box = el('div', 'lou-restore');
    box.setAttribute('role', 'alertdialog');
    var when = new Date(saved.at || Date.now());
    var snippet = (saved.type === 'html' ? (new DOMParser().parseFromString(saved.value, 'text/html').body.textContent || '') : saved.value).trim();
    box.appendChild(el('strong', '', 'You have unsaved text from ' + when.toLocaleString() + '.'));
    box.appendChild(el('q', '', snippet.length > 120 ? snippet.slice(0, 117) + '…' : snippet));
    if (typeof saved.original === 'string' && saved.original.trim() !== valueOf(node).trim()) {
      box.appendChild(el('p', '', 'The saved version of this text has changed since you started typing.'));
    }
    var row = el('div', 'lou-restore-actions');
    function close() { node.classList.remove('lou-flash'); box.remove(); askRestore(); }
    row.appendChild(button('Restore my text', '', function () {
      send({ field: saved.field, type: saved.type === 'html' ? 'html' : 'text', value: saved.value });
      hint('Saving…');
      box.remove();
    }));
    row.appendChild(button('Keep the saved version', '', function () { store.del(key); close(); }));
    box.appendChild(row);
    document.body.appendChild(box);
    node.scrollIntoView({ block: 'center' });
    node.classList.add('lou-flash');
    row.firstChild.focus();
  }

  askRestore();
})();
