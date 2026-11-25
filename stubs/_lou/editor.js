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

  var bar = document.createElement('div');
  bar.className = 'lou-editbar';
  bar.hidden = true;
  document.body.appendChild(bar);

  function hint(text) { bar.textContent = text; bar.hidden = !text; }

  var active = null, original = null;

  function finish(save) {
    if (!active) return;
    var el = active, type = el.dataset.louType || 'text';
    var value = type === 'html' ? el.innerHTML : el.innerText;
    el.removeAttribute('contenteditable');
    active = null;
    hint('');
    if (!save) { el.innerHTML = original; return; }
    if (value.trim() === (type === 'html' ? original : original_text).trim()) return;
    send({ field: el.dataset.louField, type: type, value: value });
    hint('Saving…');
  }

  var original_text = '';

  document.addEventListener('click', function (e) {
    var el = e.target.closest('[data-lou-field]');
    if (!el) {
      if (active) {
        // first click outside only saves; it doesn't follow links
        e.preventDefault();
        e.stopPropagation();
        finish(true);
      }
      return;
    }
    if (el === active) return;
    e.preventDefault();
    e.stopPropagation();
    if (active) finish(true);
    if (el.dataset.louType === 'image') {
      send({ lou: 'image', field: el.dataset.louField, src: el.currentSrc || el.src, alt: el.alt });
      return;
    }
    active = el;
    original = el.innerHTML;
    original_text = el.innerText;
    var plain = (el.dataset.louType || 'text') === 'text';
    try { el.contentEditable = plain ? 'plaintext-only' : 'true'; } catch (err) { el.contentEditable = 'true'; }
    if (el.contentEditable !== 'plaintext-only' && el.contentEditable !== 'true') el.contentEditable = 'true';
    el.focus();
    hint(plain ? 'Editing text. Enter or click outside to save, Esc to cancel.'
      : 'Editing rich text. Ctrl+B bold, Ctrl+I italic. Click outside to save, Esc to cancel.');
  }, true);

  document.addEventListener('keydown', function (e) {
    if (!active) return;
    if (e.key === 'Escape') { e.preventDefault(); finish(false); }
    else if (e.key === 'Enter' && (active.dataset.louType || 'text') === 'text') { e.preventDefault(); finish(true); }
  });

  // paste as plain text into plain fields
  document.addEventListener('paste', function (e) {
    if (!active || (active.dataset.louType || 'text') !== 'text') return;
    e.preventDefault();
    var text = (e.clipboardData || window.clipboardData).getData('text/plain');
    document.execCommand('insertText', false, text.replace(/\s+/g, ' '));
  });
})();
