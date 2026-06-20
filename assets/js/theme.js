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
