/* ═══════════════════════════════════════════════════════════
   tooltip.js — راهنمای شناور سفارشی (جایگزین tooltip بومی مرورگر)
   ───────────────────────────────────────────────────────────
   هر عنصری که صفت title یا data-tip داشته باشد، با نگه‌داشتن ماوس/فوکوس
   یک حباب راهنمای هماهنگ با UI پروژه (radius، رنگ، سایه) نشان می‌دهد.
   title بومی روی hover به data-tip منتقل می‌شود تا tooltip خام سیستم
   عامل (با radius/استایل ناهماهنگ) هرگز ظاهر نشود. خودراه‌انداز است.
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

  /* title بومی را به data-tip تبدیل می‌کند تا tooltip سیستم نمایش داده نشود */
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
    t.style.left = '0px'; t.style.top = '0px';   // اندازه‌گیری بدون محدودیت
    var w = t.offsetWidth, h = t.offsetHeight;
    var below = false;
    var x = r.left + r.width / 2 - w / 2;
    var y = r.top - h - 9;
    if (y < 6) { y = r.bottom + 9; below = true; }
    x = Math.max(6, Math.min(x, window.innerWidth - w - 6));
    t.style.left = Math.round(x) + 'px';
    t.style.top  = Math.round(y) + 'px';
    t.classList.toggle('tip-pop--below', below);
    /* موقعیت افقی فلش نسبت به مرکز عنصر */
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

  /* دسترس‌پذیری: نمایش فقط هنگام فوکوس با صفحه‌کلید (focus-visible)، نه با کلیک ماوس */
  document.addEventListener('focusin', function (e) {
    var el = e.target.closest && e.target.closest('[title],[data-tip]');
    if (!el || !text(el)) return;
    /* فوکوس ناشی از کلیک ماوس نباید tooltip را فوری باز کند؛ فقط فوکوس کیبوردی */
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
