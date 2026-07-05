'use strict';
// ═══════════════════════════════════════════════════════════
// actions.js — دیسپچرِ سراسریِ event-delegation
// ───────────────────────────────────────────────────────────
// جایگزینِ هندلرهای inline (onclick/onchange/oninput) تا CSP بتواند
// script-src را بدون 'unsafe-inline' اجرا کند. عناصر به‌جای on* از
// data-attribute استفاده می‌کنند و منطق در یک رجیستریِ مرکزی ثبت می‌شود.
//
// نگاشتِ رویداد → attribute (جدا برای هر نوع تا کلیک روی چک‌باکس با
// رویدادِ change دوباره شلیک نشود):
//   click  → data-act
//   change → data-change
//   input  → data-input
//   submit → data-submit
//
// هر عنصر یک اکشن دارد؛ دیسپچر نزدیک‌ترین [data-*] را می‌یابد و هندلرِ
// ثبت‌شده را با (el, event) صدا می‌زند. چون شنونده روی document است،
// عناصرِ تولیدشده‌ی داینامیک هم بدونِ سیم‌کشیِ جداگانه پوشش می‌گیرند.
// ═══════════════════════════════════════════════════════════
window.Actions = (function () {
  const reg = {};

  const MAP = { click: 'act', change: 'change', input: 'input', submit: 'submit' };

  Object.keys(MAP).forEach(function (type) {
    const attr = MAP[type];
    document.addEventListener(type, function (e) {
      const el = e.target.closest('[data-' + attr + ']');
      if (!el) return;
      const fn = reg[el.dataset[attr]];
      if (fn) fn(el, e);
    });
  });

  return {
    // ثبتِ گروهی اکشن‌ها: Actions.register({ name: (el, e) => {...} })
    register: function (map) { Object.assign(reg, map); return this; },
  };
})();
