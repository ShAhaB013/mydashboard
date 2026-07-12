'use strict';
/* ═══════════════════════════════════════════════════════════
   theme.js — shared theme manager (light / dark)
   ───────────────────────────────────────────────────────────
   • Smooth, lag-free fade: on switch, the .theme-fade class only transitions
     cheap color properties and is removed immediately once it's done.
   • Synced across tabs: changing the theme in one tab updates the others too.
   • Updates <meta name="theme-color"> for the mobile address bar.
   • Respects prefers-color-scheme when the user hasn't made a choice.
   • Auto-binds to #themeToggle / .theme-toggle / [data-theme-toggle].
   • window.toggleTheme() is provided for compatibility with legacy onclick.

   Note: FOUC (flash of unstyled content) prevention is handled by a small
   inline script in each page's <head>; this file loads with defer.
   ═══════════════════════════════════════════════════════════ */
(function () {
  const KEY    = 'theme';
  const DARK   = 'dark';
  const LIGHT  = 'light';
  const root   = document.documentElement;

  /* Mobile address bar color for each theme */
  const META_COLOR = { light: '#3e7de7', dark: '#0d1117' };

  function current() {
    return root.getAttribute('data-theme') === DARK ? DARK : LIGHT;
  }

  function updateMeta(theme) {
    let m = document.querySelector('meta[name="theme-color"]');
    if (!m) {
      m = document.createElement('meta');
      m.setAttribute('name', 'theme-color');
      document.head.appendChild(m);
    }
    m.setAttribute('content', META_COLOR[theme] || META_COLOR.light);
  }

  function updateToggleLabels(theme) {
    const label = theme === DARK ? 'تغییر به حالت روشن' : 'تغییر به حالت تاریک';
    document
      .querySelectorAll('#themeToggle, .theme-toggle, [data-theme-toggle]')
      .forEach(btn => btn.setAttribute('aria-label', label));
  }

  /* Color fade duration — kept in sync with animation-duration in CSS */
  const FADE_MS = 320;

  /* Actual theme swap (no animation) */
  function swap(theme) {
    if (theme === DARK) root.setAttribute('data-theme', DARK);
    else                root.removeAttribute('data-theme');
    updateMeta(theme);
    updateToggleLabels(theme);
  }

  /* Applies the theme with a smooth, lag-free fade.
     Primary path: View Transitions API — takes a snapshot of the before/after
     state and cross-fades it on the GPU; no per-frame repaint happens, so it
     stays lag-free even on heavy pages (a dashboard with dozens of cards +
     aurora + backdrop-blur). Fallback: CSS fade via .theme-fade for browsers
     without support. */
  function apply(theme, persist = true, broadcast = true) {
    if (persist) {
      try { localStorage.setItem(KEY, theme); } catch (e) {}
    }

    const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    if (!reduce && typeof document.startViewTransition === 'function') {
      /* Disable per-element transitions under the snapshot so we don't get a
         parallel repaint; only the View Transition performs the cross-fade. */
      root.classList.add('theme-instant');
      const vt = document.startViewTransition(() => swap(theme));
      vt.finished.finally(() => root.classList.remove('theme-instant'));
      void broadcast;
      return;
    }

    if (reduce) { swap(theme); void broadcast; return; }

    /* Fallback: smooth CSS fade */
    root.classList.add('theme-fade');
    void root.offsetWidth; // lock in the starting color
    swap(theme);
    clearTimeout(apply._timer);
    apply._timer = setTimeout(() => root.classList.remove('theme-fade'), FADE_MS);

    void broadcast; // localStorage itself fires the storage event for other tabs
  }

  function toggle() {
    apply(current() === DARK ? LIGHT : DARK);
  }

  /* Available for onclick="toggleTheme()" in legacy templates */
  window.toggleTheme = toggle;
  window.ThemeManager = { apply, toggle, current };

  /* Auto-binds to buttons (no onclick needed) */
  function bind() {
    document
      .querySelectorAll('#themeToggle, .theme-toggle, [data-theme-toggle]')
      .forEach(btn => {
        if (btn.dataset.themeBound) return;
        btn.dataset.themeBound = '1';
        btn.addEventListener('click', toggle);
      });
    updateToggleLabels(current());
    updateMeta(current());
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bind);
  } else {
    bind();
  }

  /* Sync across tabs */
  window.addEventListener('storage', e => {
    if (e.key === KEY && (e.newValue === DARK || e.newValue === LIGHT)) {
      apply(e.newValue, false, false);
    }
  });

  /* System theme change — only when the user hasn't made a manual choice */
  const mq = window.matchMedia('(prefers-color-scheme: dark)');
  const onSys = e => {
    let saved = null;
    try { saved = localStorage.getItem(KEY); } catch (err) {}
    if (!saved) apply(e.matches ? DARK : LIGHT, false, false);
  };
  if (mq.addEventListener) mq.addEventListener('change', onSys);
  else if (mq.addListener) mq.addListener(onSys);
})();

/* ═══════════════════════════════════════════════════════════
   Ripple effect (click wave) on header buttons — Material/Telegram style.
   Not applied to the bell button (the notification badge sits outside the box).
   ═══════════════════════════════════════════════════════════ */
(function () {
  // Note: .theme-toggle intentionally has no ripple — the button rotation +
  // icon morph + theme fade all run at once, and adding a ripple caused conflicts and lag.
  const SEL = '.hdr-btn, .user-menu-btn, .btn, .btn-icon, .chip,'
    + ' .auth-btn, .user-menu-item, .notif-drop-item, .login-submit-btn,'
    + ' .profile-submit-btn, .login-tab, .pagination-btn, .pagination-goto-spin, .notif-view-btn, .notif-row,'
    + ' .notif-search-btn, .notif-adv-toggle, .notif-adv-apply, .cselect-option,'
    + ' .pg-btn, .access-tool-label, .deco-opt, .nm-adv-toggle, .section-box-head,'
    + ' .notif-drop-view-all, .notif-detail-view-all, .notif-detail-close-btn,'
    + ' .notif-detail-close, .header-search-close, .clear-button,'
    + ' .nd-close-btn, .nd-close-action,'
    + ' .login-forgot-link, .login-back-link, .forgot-back-top, .reg-back-btn,'
    + ' .reg-resend, .login-pass-toggle, .login-pass-gen, .profile-pass-toggle,'
    + ' .profile-link-btn, .tm-close,'
    + ' .tm-combo-toggle, .tm-combo-option, .toast-msg-close,'
    + ' .reorder-toggle, .cab-btn, .card-add-tile, .acct-sess-kill, .acct-killall-btn, .profile-tab';
  document.addEventListener('pointerdown', function (e) {
    const btn = e.target.closest(SEL);
    if (!btn || btn.disabled || btn.getAttribute('aria-disabled') === 'true') return;
    const rect = btn.getBoundingClientRect();
    const size = Math.max(rect.width, rect.height);
    const r = document.createElement('span');
    r.className = 'ripple';
    r.style.width = r.style.height = size + 'px';
    r.style.left = (e.clientX - rect.left - size / 2) + 'px';
    r.style.top  = (e.clientY - rect.top  - size / 2) + 'px';
    btn.appendChild(r);
    const kill = () => r.remove();
    r.addEventListener('animationend', kill);
    // Safety net: if animationend never fires for some reason, don't let the ripple outlive the animation
    setTimeout(kill, 700);
  });
  /* bfcache bug fix: if we navigate away mid-ripple, the wave's span stays in
     the frozen page and gets seen playing for one frame on back/forward
     navigation. Definitive fix: never freeze the page with a live ripple —
     all ripples are cleared before any navigation, before the page hides,
     and when restoring from the cache. */
  function purgeRipples() {
    document.querySelectorAll('span.ripple').forEach(function (n) { n.remove(); });
  }
  window.addEventListener('pagehide', purgeRipples);
  window.addEventListener('pageshow', purgeRipples);          // unconditional, regardless of persisted
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'hidden') purgeRipples();
  });
  /* Header/menu links open instantly via prerender and the ripple never gets
     seen; we hold navigation for ~160ms so the click wave gets to play, but
     clear the ripples right before navigating so the page never freezes with
     a half-finished wave. */
  document.addEventListener('click', function (e) {
    const a = e.target.closest(SEL);
    if (!a || a.tagName !== 'A') return;
    const href = a.getAttribute('href');
    if (!href || href.charAt(0) === '#' || a.target === '_blank') return;
    if (e.defaultPrevented || e.metaKey || e.ctrlKey || e.shiftKey || e.button) return;
    e.preventDefault();
    setTimeout(function () { purgeRipples(); window.location.href = href; }, 160);
  });
})();

/* ═══════════════════════════════════════════════════════════
   Sticky header on scroll: scrolling down adds the .is-stuck class
   (the bar sticks to the top and gets a faint shadow); scrolling back up removes it.
   ═══════════════════════════════════════════════════════════ */
(function () {
  const header = document.querySelector('.app-header');
  if (!header) return;
  let ticking = false;
  function update() {
    const y = window.scrollY;
    // Dual threshold (hysteresis) so a few pixels of scroll jitter (e.g. the
    // padding-top change caused by is-stuck itself) doesn't keep toggling the
    // class back and forth and making the header jitter.
    if (y > 24) header.classList.add('is-stuck');
    else if (y < 8) header.classList.remove('is-stuck');
    ticking = false;
  }
  window.addEventListener('scroll', function () {
    if (!ticking) { requestAnimationFrame(update); ticking = true; }
  }, { passive: true });
  update();
})();

/* ═══════════════════════════════════════════════════════════
   Toast — floating message with colored icon + title + description + close button
   (shared across all public pages: dashboard/login/profile/notifications).
   Toast.show(message, type, title?) — type: success | error | warning | info
   Only ever one toast shown at a time; a new toast takes the previous one's
   place instead of stacking on top of it.
   ═══════════════════════════════════════════════════════════ */
const Toast = {
  _wrap: null, _timer: null,
  _ICON: {
    success: '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="M22 4L12 14.01l-3-3"/>',
    error:   '<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>',
    warning: '<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
    info:    '<circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/>',
  },
  _TITLE: { success: 'موفقیت', error: 'خطا', warning: 'هشدار', info: 'اطلاع‌رسانی' },
  _DURATION: 4500,
  show(message, type, title) {
    type = (type && this._ICON[type]) ? type : 'success';
    if (!this._wrap) this._wrap = document.getElementById('toastWrap');
    if (!this._wrap) return;
    clearTimeout(this._timer);
    // One toast at a time: any previous message immediately gives way to the new one.
    this._wrap.innerHTML = '';
    const t = document.createElement('div');
    t.className = 'toast-msg ' + type;
    t.innerHTML =
      '<span class="toast-msg-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">' + this._ICON[type] + '</svg></span>'
      + '<div class="toast-msg-body"><strong class="toast-msg-title"></strong><span class="toast-msg-text"></span></div>'
      + '<button type="button" class="toast-msg-close" aria-label="بستن"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>'
      + '<span class="toast-msg-progress" style="animation-duration:' + this._DURATION + 'ms"></span>';
    t.querySelector('.toast-msg-title').textContent = title || this._TITLE[type];
    t.querySelector('.toast-msg-text').textContent  = message;
    const dismiss = () => { t.classList.add('hide'); setTimeout(() => t.remove(), 250); };
    t.querySelector('.toast-msg-close').addEventListener('click', () => { clearTimeout(this._timer); dismiss(); });
    this._wrap.appendChild(t);
    this._timer = setTimeout(dismiss, this._DURATION);
  },
};
window.Toast = Toast;

