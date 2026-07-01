'use strict';
/* ═══════════════════════════════════════════════════════════
   theme.js — مدیر تم مشترک (light / dark)
   ───────────────────────────────────────────────────────────
   • محو نرم و بدون لگ: هنگام سوییچ، کلاس .theme-fade فقط ویژگی‌های
     رنگی ارزان را ترنزیشن می‌دهد و بلافاصله بعد از پایان برداشته می‌شود.
   • همگام بین تب‌ها: تغییر تم در یک تب، بقیه تب‌ها را هم آپدیت می‌کند.
   • آپدیت <meta name="theme-color"> برای نوار آدرس موبایل.
   • احترام به prefers-color-scheme وقتی کاربر انتخابی نکرده.
   • خودکار به #themeToggle / .theme-toggle / [data-theme-toggle] وصل می‌شود.
   • window.toggleTheme() برای سازگاری با onclick قدیمی فراهم است.

   نکته: جلوگیری از FOUC (فلش لحظه‌ای) با اسکریپت inline کوچک داخل
   <head> هر صفحه انجام می‌شود؛ این فایل با defer لود می‌شود.
   ═══════════════════════════════════════════════════════════ */
(function () {
  const KEY    = 'theme';
  const DARK   = 'dark';
  const LIGHT  = 'light';
  const root   = document.documentElement;

  /* رنگ نوار آدرس موبایل برای هر تم */
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

  /* مدت محو رنگ‌ها — با animation-duration در CSS هماهنگ است */
  const FADE_MS = 320;

  /* تعویض واقعی تم (بدون انیمیشن) */
  function swap(theme) {
    if (theme === DARK) root.setAttribute('data-theme', DARK);
    else                root.removeAttribute('data-theme');
    updateMeta(theme);
    updateToggleLabels(theme);
  }

  /* اعمال تم با محو نرم و بدون لگ.
     مسیر اصلی: View Transitions API — یک اسنپ‌شات از حالت قبل و بعد گرفته و
     روی GPU کراس‌فید می‌شود؛ هیچ repaint هر-فریمی رخ نمی‌دهد، پس روی صفحات
     سنگین (داشبورد با ده‌ها کارت + aurora + backdrop-blur) هم بدون لگ است.
     فالبک: محو CSS با کلاس .theme-fade برای مرورگرهای بدون پشتیبانی. */
  function apply(theme, persist = true, broadcast = true) {
    if (persist) {
      try { localStorage.setItem(KEY, theme); } catch (e) {}
    }

    const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    if (!reduce && typeof document.startViewTransition === 'function') {
      /* زیر اسنپ‌شات، ترنزیشن‌های هر-المان خاموش شوند تا repaint موازی نداشته
         باشیم؛ کراس‌فید را فقط View Transition انجام می‌دهد. */
      root.classList.add('theme-instant');
      const vt = document.startViewTransition(() => swap(theme));
      vt.finished.finally(() => root.classList.remove('theme-instant'));
      void broadcast;
      return;
    }

    if (reduce) { swap(theme); void broadcast; return; }

    /* فالبک: محو نرم CSS */
    root.classList.add('theme-fade');
    void root.offsetWidth; // قفل‌کردن رنگ مبدا
    swap(theme);
    clearTimeout(apply._timer);
    apply._timer = setTimeout(() => root.classList.remove('theme-fade'), FADE_MS);

    void broadcast; // localStorage خودش رویداد storage را برای تب‌های دیگر می‌فرستد
  }

  function toggle() {
    apply(current() === DARK ? LIGHT : DARK);
  }

  /* در دسترس برای onclick="toggleTheme()" در قالب‌های قدیمی */
  window.toggleTheme = toggle;
  window.ThemeManager = { apply, toggle, current };

  /* اتصال خودکار به دکمه‌ها (بدون نیاز به onclick) */
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

  /* همگام‌سازی بین تب‌ها */
  window.addEventListener('storage', e => {
    if (e.key === KEY && (e.newValue === DARK || e.newValue === LIGHT)) {
      apply(e.newValue, false, false);
    }
  });

  /* تغییر تم سیستم — فقط وقتی کاربر انتخاب دستی نکرده باشد */
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
   افکت ripple (موج کلیک) روی دکمه‌های هدر — سبک متریال/تلگرام.
   روی دکمه زنگ اعمال نمی‌شود (بج اعلان بیرون کادر است).
   ═══════════════════════════════════════════════════════════ */
(function () {
  const SEL = '.hdr-btn, .theme-toggle, .user-menu-btn, .btn, .btn-icon, .chip,'
    + ' .auth-btn, .user-menu-item, .notif-drop-item, .login-submit-btn,'
    + ' .profile-submit-btn, .login-tab, .npag-btn, .notif-view-btn, .notif-row,'
    + ' .notif-search-btn, .notif-adv-toggle, .notif-adv-apply, .cselect-option,'
    + ' .pg-btn, .access-tool-label, .deco-opt, .nm-adv-toggle, .section-box-head,'
    + ' .notif-drop-view-all, .notif-detail-view-all, .notif-detail-close-btn,'
    + ' .notif-detail-close, .header-search-close, .clear-button,'
    + ' .nd-close-btn, .nd-close-action,'
    + ' .login-forgot-link, .login-back-link, .forgot-back-top, .reg-back-btn,'
    + ' .reg-resend, .login-pass-toggle, .login-pass-gen, .profile-pass-toggle,'
    + ' .profile-link-btn, .tm-icon-opt, .tm-deco-opt, .tm-close,'
    + ' .reorder-toggle, .cab-btn, .card-add-tile';
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
    r.addEventListener('animationend', () => r.remove());
  });
  /* رفع باگ bfcache: اگر حین پخش ریپل به صفحه دیگری برویم، span موج در صفحهٔ
     منجمدشده باقی می‌ماند و هنگام بازگشت (back/forward) دوباره پخش می‌شود.
     پیش از منجمدشدن صفحه و نیز هنگام بازیابی از کش، همهٔ ریپل‌ها را پاک می‌کنیم. */
  function purgeRipples() {
    document.querySelectorAll('span.ripple').forEach(function (n) { n.remove(); });
  }
  window.addEventListener('pagehide', purgeRipples);
  window.addEventListener('pageshow', function (e) { if (e.persisted) purgeRipples(); });
  /* لینک‌های هدر/منو با prerender فوری باز می‌شوند و ریپل دیده نمی‌شود؛
     ناوبری را ~160ms نگه می‌داریم تا موج کلیک پخش شود. */
  document.addEventListener('click', function (e) {
    const a = e.target.closest(SEL);
    if (!a || a.tagName !== 'A') return;
    const href = a.getAttribute('href');
    if (!href || href.charAt(0) === '#' || a.target === '_blank') return;
    if (e.defaultPrevented || e.metaKey || e.ctrlKey || e.shiftKey || e.button) return;
    e.preventDefault();
    setTimeout(function () { window.location.href = href; }, 160);
  });
})();

/* ═══════════════════════════════════════════════════════════
   هدر چسبان هنگام اسکرول: با اسکرول به پایین کلاس .is-stuck اضافه می‌شود
   (نوار به بالای صفحه می‌چسبد و سایه‌ی کم‌رنگ می‌گیرد)؛ با برگشت به بالا حذف.
   ═══════════════════════════════════════════════════════════ */
(function () {
  const header = document.querySelector('.app-header');
  if (!header) return;
  let ticking = false;
  function update() {
    header.classList.toggle('is-stuck', window.scrollY > 4);
    ticking = false;
  }
  window.addEventListener('scroll', function () {
    if (!ticking) { requestAnimationFrame(update); ticking = true; }
  }, { passive: true });
  update();
})();

