/*
 * Lou live viewer: injected into "live" preview links. Every few seconds it fetches version.json
 * (a static file Lou rewrites on each preview rebuild) and reloads the page when it changed,
 * keeping the scroll position. No PHP, no websockets: just a tiny static request while the tab is visible.
 */
(function () {
  'use strict';
  var script = document.currentScript;
  var url = script && script.dataset.version;
  if (!url || !window.fetch) return;
  var key = 'lou-viewer-scroll:' + location.pathname;
  var current = null;
  var interval = 2500;

  try {
    var y = sessionStorage.getItem(key);
    if (y !== null) {
      sessionStorage.removeItem(key);
      window.scrollTo(0, parseInt(y, 10) || 0);
    }
  } catch (e) { /* storage may be blocked */ }

  var badge = document.createElement('div');
  badge.textContent = '● Live';
  badge.setAttribute('aria-hidden', 'true');
  var s = badge.style;
  s.position = 'fixed'; s.right = '12px'; s.bottom = '12px'; s.zIndex = '2147483647';
  s.padding = '4px 10px'; s.borderRadius = '999px'; s.font = '600 12px/1.4 system-ui, sans-serif';
  s.background = '#067647'; s.color = '#fff'; s.boxShadow = '0 1px 4px rgba(0,0,0,.25)'; s.pointerEvents = 'none';
  document.addEventListener('DOMContentLoaded', function () { document.body.appendChild(badge); });

  function check() {
    if (document.visibilityState !== 'visible') return;
    fetch(url + '?t=' + Date.now(), { cache: 'no-store' }).then(function (r) {
      if (!r.ok) throw new Error(String(r.status));
      return r.json();
    }).then(function (d) {
      if (current === null) {
        current = d.v;
      } else if (d.v !== current) {
        try { sessionStorage.setItem(key, String(window.scrollY)); } catch (e) { /* ignore */ }
        location.reload();
      }
      s.background = '#067647';
    }).catch(function () {
      s.background = '#b54708'; // link expired or offline; keep trying quietly
    });
  }
  check();
  setInterval(check, interval);
  document.addEventListener('visibilitychange', check);
})();
