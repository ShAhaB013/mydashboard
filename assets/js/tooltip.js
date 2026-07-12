/* ═══════════════════════════════════════════════════════════
   tooltip.js — custom floating tooltip (replaces the browser's native tooltip)
   ───────────────────────────────────────────────────────────
   Any element with a title or data-tip attribute shows a tooltip bubble
   styled to match the project UI (radius, color, shadow) on hover/focus.
   The native title is moved to data-tip on hover so the OS's raw tooltip
   (with mismatched radius/style) never appears. Self-initializing.
   ═══════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  if (window.__tipReady) return;
  window.__tipReady = true;

  var tip = null, curEl = null, showT = 0, hideT = 0;
  var SHOW_DELAY = 320, HIDE_DELAY = 60;

  function ensure() {
    if (tip) return tip;
    tip = document.createElement('div');
    tip.className = 'tip-pop';
    tip.setAttribute('role', 'tooltip');
    document.body.appendChild(tip);
    return tip;
  }

  /* Moves the native title to data-tip so the system tooltip never shows */
  function text(el) {
    if (el.hasAttribute('title')) {
      var t = el.getAttribute('title');
      if (t) { el.setAttribute('data-tip', t); }
      el.removeAttribute('title');
    }
    return el.getAttribute('data-tip') || '';
  }

  function place(el) {
    var r = el.getBoundingClientRect();
    var t = ensure();
    t.style.left = '0px'; t.style.top = '0px';   // measure unconstrained
    var w = t.offsetWidth, h = t.offsetHeight;
    var below = false;
    var x = r.left + r.width / 2 - w / 2;
    var y = r.top - h - 9;
    if (y < 6) { y = r.bottom + 9; below = true; }
    x = Math.max(6, Math.min(x, window.innerWidth - w - 6));
    t.style.left = Math.round(x) + 'px';
    t.style.top  = Math.round(y) + 'px';
    t.classList.toggle('tip-pop--below', below);
    /* Arrow's horizontal position relative to the element's center */
    t.style.setProperty('--tip-ax', Math.round(r.left + r.width / 2 - x) + 'px');
  }

  function show(el) {
    var txt = text(el);
    if (!txt) return;
    var t = ensure();
    t.textContent = txt;
    place(el);
    requestAnimationFrame(function () { t.classList.add('is-on'); });
  }

  function hide() {
    if (tip) tip.classList.remove('is-on');
    curEl = null;
  }

  function enter(el) {
    if (el === curEl) return;
    if (!text(el)) return;
    curEl = el;
    clearTimeout(hideT); clearTimeout(showT);
    showT = setTimeout(function () { show(el); }, SHOW_DELAY);
  }

  function leave() {
    clearTimeout(showT);
    curEl = null;
    clearTimeout(hideT);
    hideT = setTimeout(hide, HIDE_DELAY);
  }

  document.addEventListener('mouseover', function (e) {
    var el = e.target.closest && e.target.closest('[title],[data-tip]');
    if (el) enter(el);
  }, true);
  document.addEventListener('mouseout', function (e) {
    var el = e.target.closest && e.target.closest('[data-tip],[title]');
    if (!el) return;
    if (e.relatedTarget && el.contains(e.relatedTarget)) return;
    if (el === curEl) leave();
  }, true);

  /* Accessibility: only show on keyboard focus (focus-visible), not on mouse click */
  document.addEventListener('focusin', function (e) {
    var el = e.target.closest && e.target.closest('[title],[data-tip]');
    if (!el || !text(el)) return;
    /* Focus caused by a mouse click must not open the tooltip immediately; keyboard focus only */
    try { if (!el.matches(':focus-visible')) return; } catch (_) { return; }
    curEl = el; clearTimeout(hideT); clearTimeout(showT);
    showT = setTimeout(function () { show(el); }, SHOW_DELAY);
  }, true);
  document.addEventListener('focusout', function () { clearTimeout(showT); hide(); }, true);
  document.addEventListener('mousedown', hide, true);
  document.addEventListener('pointerdown', function () { clearTimeout(showT); hide(); }, true);
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') hide(); }, true);
  window.addEventListener('scroll', hide, true);
  window.addEventListener('resize', hide);
})();
