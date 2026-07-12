/* ═══════════════════════════════════════════════════════════
   field.js — shared helper for the field component (.field)
   Manages states: idle / focus / error / success / loading / disabled
   Usage:
     Field.set(input, 'error',   'Invalid email format');
     Field.set(input, 'success', 'Looks good');
     Field.set(input, 'loading');          // checking…
     Field.set(input, 'disabled');
     Field.clear(input);                    // → idle
   `input` can be the element itself or its id (a string).
   ═══════════════════════════════════════════════════════════ */
(function () {
  'use strict';

  const ICONS = {
    error:
      '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><line x1="12" y1="7.5" x2="12" y2="13"/><circle cx="12" cy="16.5" r=".6" fill="currentColor" stroke="none"/></svg>',
    success:
      '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M8.5 12.5l2.4 2.4 4.6-5.1"/></svg>',
    loading:
      '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M21 12a9 9 0 1 1-2.64-6.36"/><path d="M21 4v5h-5"/></svg>',
  };

  function resolve(input) {
    return (typeof input === 'string') ? document.getElementById(input) : input;
  }
  function fieldOf(el) {
    return (el && el.closest) ? el.closest('.field') : null;
  }

  /* Sets a field's state + message/icon */
  function set(input, state, message) {
    const el    = resolve(input);
    const field = fieldOf(el);
    if (!field) return;

    field.setAttribute('data-state', state);

    if (el) el.setAttribute('aria-invalid', state === 'error' ? 'true' : 'false');

    const status = field.querySelector('.field-status');
    if (status) status.innerHTML = ICONS[state] || '';

    // Message below the field (error/success only)
    const msgEl = field.querySelector('.field-msg');
    if (msgEl) {
      const txt = msgEl.querySelector('.field-msg-text');
      const ico = msgEl.querySelector('.field-msg-icon');
      if (message && (state === 'error' || state === 'success')) {
        if (txt) txt.textContent = message;
        if (ico) ico.innerHTML = ICONS[state] || '';
      } else {
        if (txt) txt.textContent = '';
        if (ico) ico.innerHTML = '';
      }
    }

    if (el && 'disabled' in el) el.disabled = (state === 'disabled');
  }

  function clear(input) { set(input, 'idle'); }

  /* Auto-binds focus/blur: only while the field is in idle/focus state,
     so an error/success/loading/disabled verdict never gets overwritten. */
  function bind(scope) {
    const root = scope || document;
    root.querySelectorAll('.field .field-input').forEach(inp => {
      if (inp.__fieldBound) return;
      inp.__fieldBound = true;
      inp.addEventListener('focus', () => {
        const f = fieldOf(inp);
        const s = f && f.getAttribute('data-state');
        if (f && (!s || s === 'idle')) f.setAttribute('data-state', 'focus');
      });
      inp.addEventListener('blur', () => {
        const f = fieldOf(inp);
        if (f && f.getAttribute('data-state') === 'focus') f.setAttribute('data-state', 'idle');
      });
    });
  }

  window.Field = { set, clear, bind, ICONS };
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => bind());
  } else {
    bind();
  }
})();
