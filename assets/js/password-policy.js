'use strict';
// ═══════════════════════════════════════════════════════════
// PasswordPolicy — single source of truth for client-side password rules
//   (kept in sync with app/Core/PasswordPolicy.php) + strong random password generation
//   Used in: login.js (forgot password), profile.js (change password),
//   admin.js (add/edit user)
// ═══════════════════════════════════════════════════════════
window.PasswordPolicy = (function () {
  const RULES = [
    { key: 'len',     test: v => v.length >= 10 && v.length <= 64 },
    { key: 'lower',   test: v => /[a-z]/.test(v) },
    { key: 'upper',   test: v => /[A-Z]/.test(v) },
    { key: 'digit',   test: v => /[0-9]/.test(v) },
    { key: 'special', test: v => /[^A-Za-z0-9]/.test(v) },
  ];
  const MSG = 'رمز عبور باید بین ۱۰ تا ۶۴ کاراکتر و شامل حروف کوچک و بزرگ انگلیسی، عدد و نماد باشد.';
  const OK_IC =
    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6L9 17l-5-5"/></svg>';
  const PENDING_IC =
    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="8"/></svg>';
  const EYE_OFF_SVG =
    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/></svg>';

  function meets(val) {
    return !!val && RULES.every(r => r.test(val));
  }

  // Live checklist update (panelId = id of the .pass-rules element)
  function updateChecklist(panelId, val) {
    const panel = document.getElementById(panelId);
    if (!panel) return;
    panel.hidden = false;
    RULES.forEach(r => {
      const row = panel.querySelector('.pass-rule[data-rule="' + r.key + '"]');
      if (!row) return;
      const ok = r.test(val);
      row.classList.toggle('is-ok', ok);
      const ic = row.querySelector('.pass-rule-ic');
      if (ic) ic.innerHTML = ok ? OK_IC : PENDING_IC;
    });
  }

  // Generates a strong, random, unique password (Web Crypto); fills the
  // password/confirm fields and switches the adjacent eye button (matched via
  // data-act="togglePass"[data-target]) to "show text" mode. panelId is
  // optional, for updating the checklist.
  function generate(passId, confirmId, panelId) {
    const U = 'ABCDEFGHJKLMNPQRSTUVWXYZ';   // excludes ambiguous I, O
    const L = 'abcdefghijkmnopqrstuvwxyz';   // excludes ambiguous l
    const D = '23456789';                    // excludes ambiguous 0, 1
    const S = '!@#$%^&*-_=+?';
    const ALL = U + L + D + S;
    const rnd = (n) => { const a = new Uint32Array(1); crypto.getRandomValues(a); return a[0] % n; };

    const len = 14 + rnd(5); // length 14 to 18
    const out = [U[rnd(U.length)], L[rnd(L.length)], D[rnd(D.length)], S[rnd(S.length)]]; // at least one from each class
    while (out.length < len) out.push(ALL[rnd(ALL.length)]);
    for (let i = out.length - 1; i > 0; i--) { const j = rnd(i + 1); [out[i], out[j]] = [out[j], out[i]]; } // shuffle
    const pwd = out.join('');

    const p = document.getElementById(passId);
    if (!p) return pwd;
    const c = confirmId ? document.getElementById(confirmId) : null;
    p.value = pwd; if (c) c.value = pwd;
    p.type = 'text'; // show the generated password so the user can see/copy it
    const toggleBtn = document.querySelector('[data-act="togglePass"][data-target="' + passId + '"]');
    if (toggleBtn) toggleBtn.innerHTML = EYE_OFF_SVG;
    if (panelId) updateChecklist(panelId, pwd);
    p.focus();
    return pwd;
  }

  return { RULES, MSG, meets, updateChecklist, generate };
})();
