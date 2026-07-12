'use strict';
// ═══════════════════════════════════════════════════════════
// actions.js — global event-delegation dispatcher
// ───────────────────────────────────────────────────────────
// Replaces inline handlers (onclick/onchange/oninput) so CSP can enforce
// script-src without 'unsafe-inline'. Elements use data-attributes instead
// of on*, and the logic is registered in one central registry.
//
// Event → attribute mapping (kept separate per type so a click on a
// checkbox doesn't also fire again for the change event):
//   click   → data-act
//   change  → data-change
//   input   → data-input
//   submit  → data-submit
//   keydown → data-keydown (the handler itself must check e.key)
//
// Each element has one action; the dispatcher finds the closest [data-*]
// and calls the registered handler with (el, event). Since the listener is
// on document, dynamically generated elements are covered without needing
// separate wiring.
// ═══════════════════════════════════════════════════════════
window.Actions = (function () {
  const reg = {};

  const MAP = { click: 'act', change: 'change', input: 'input', submit: 'submit', keydown: 'keydown' };

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
    // Batch-registers actions: Actions.register({ name: (el, e) => {...} })
    register: function (map) { Object.assign(reg, map); return this; },
  };
})();
