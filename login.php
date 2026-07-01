<?php
require_once __DIR__ . '/version.php';
// Bootstrap مشترک: autoload + config + DB + session
$config = require __DIR__ . '/bootstrap.php';

// اگر از قبل وارد شده، مستقیم به داشبورد
if (UserSession::check()) {
    header('Location: /');
    exit;
}

$v_css   = asset_v(__DIR__ . '/assets/css/style.css');
$v_theme = asset_v(__DIR__ . '/assets/js/theme.js');
$v_field = asset_v(__DIR__ . '/assets/js/field.js');
$v_loginjs = asset_v(__DIR__ . '/assets/js/login.js');
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <meta name="theme-color" content="#3e7de7">
  <meta name="color-scheme" content="light dark">
  <meta name="robots" content="noindex">
  <title>ورود — داشبورد ابزارهای کمکی</title>
  <link rel="preload" href="fonts/vazir-font/Vazir-Variable.woff2" as="font" type="font/woff2" crossorigin="anonymous">
  <link rel="stylesheet" href="/assets/css/style.css?v=<?= $v_css ?>">
  <script>
    (function () {
      const saved = localStorage.getItem('theme');
      const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
      if (saved === 'dark' || (!saved && prefersDark)) {
        document.documentElement.setAttribute('data-theme', 'dark');
      }
    })();
  </script>
  <script src="/assets/js/theme.js?v=<?= $v_theme ?>" defer></script>
  <script src="/assets/js/tooltip.js?v=<?= asset_v(__DIR__ . '/assets/js/tooltip.js') ?>" defer></script>
</head>
<body class="login-page-body">

  <main class="login-page" role="main">

    <!-- پس‌زمینه زنده: گرادیانت نرم متحرک + حباب‌های شناور (سبک و هماهنگ با تم) -->
    <div class="login-bg" aria-hidden="true">
      <span class="login-bubble b1"></span>
      <span class="login-bubble b2"></span>
      <span class="login-bubble b3"></span>
      <span class="login-bubble b4"></span>
      <span class="login-bubble b5"></span>
    </div>

    <div class="login-card">

      <div class="login-card-head">
        <div class="login-logo" aria-hidden="true">
          <svg class="login-logo-img" viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
            <defs>
              <linearGradient id="ll-ring" x1="0" y1="0" x2="1" y2="1">
                <stop offset="0%"   stop-color="var(--color-accent-light)"/>
                <stop offset="100%" stop-color="var(--color-accent-dark)"/>
              </linearGradient>
            </defs>
            <!-- حلقه بیرونی هم‌رنگ اکسنت -->
            <circle cx="50" cy="50" r="46" fill="none" stroke="url(#ll-ring)" stroke-width="3" opacity="0.85"/>
            <!-- نقاط مدار ساده هم‌رنگ اکسنت -->
            <g fill="currentColor" opacity="0.55">
              <circle cx="50" cy="8"  r="2.4"/>
              <circle cx="82" cy="24" r="2.4"/>
              <circle cx="82" cy="76" r="2.4"/>
              <circle cx="18" cy="24" r="2.4"/>
              <circle cx="18" cy="76" r="2.4"/>
            </g>
            <g stroke="currentColor" stroke-width="1.6" stroke-linecap="round" opacity="0.35">
              <line x1="50" y1="10" x2="50" y2="20"/>
              <line x1="80" y1="26" x2="72" y2="33"/>
              <line x1="80" y1="74" x2="72" y2="67"/>
              <line x1="20" y1="26" x2="28" y2="33"/>
              <line x1="20" y1="74" x2="28" y2="67"/>
            </g>
            <!-- دنده ۸-دندانه هم‌رنگ اکسنت (outer r=14, inner r=10) -->
            <path id="ll-gear"
                  d="M50,36 L53.8,40.8 L59.9,40.1 L59.2,46.2 L64,50 L59.2,53.8 L59.9,59.9 L53.8,59.2 L50,64 L46.2,59.2 L40.1,59.9 L40.8,53.8 L36,50 L40.8,46.2 L40.1,40.1 L46.2,40.8 Z"
                  fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>
            <!-- هاب مرکزی دنده -->
            <circle cx="50" cy="50" r="6.5" fill="none" stroke="currentColor" stroke-width="2"/>
          </svg>
        </div>
        <h1 class="login-title">ورود به حساب کاربری</h1>
      </div>

      <!-- ═══ فرم ورود ═══ -->
      <form class="login-card-body login-form" id="loginForm" autocomplete="on" novalidate>
        <div class="field" data-state="idle">
          <label class="field-label" for="loginUsername">نام کاربری</label>
          <div class="field-box">
            <span class="field-type-icon" aria-hidden="true">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            </span>
            <input type="text" id="loginUsername" name="username" class="field-input" placeholder="نام کاربری"
                   autocomplete="username" dir="ltr" maxlength="190" autofocus>
          </div>
          <p class="field-msg" aria-live="polite"><span class="field-msg-icon" aria-hidden="true"></span><span class="field-msg-text"></span></p>
        </div>

        <div class="field" data-state="idle">
          <label class="field-label" for="loginPassword">رمز عبور</label>
          <div class="field-box">
            <span class="field-type-icon" aria-hidden="true">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            </span>
            <input type="password" id="loginPassword" name="password" class="field-input" placeholder="رمز عبور"
                   autocomplete="current-password" dir="ltr" maxlength="128">
            <button type="button" class="login-pass-toggle" aria-label="نمایش/مخفی کردن رمز" onclick="togglePass('loginPassword', this)">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            </button>
          </div>
          <p class="field-msg" aria-live="polite"><span class="field-msg-icon" aria-hidden="true"></span><span class="field-msg-text"></span></p>
        </div>

        <p class="login-error" id="loginError" aria-live="polite"></p>

        <button type="submit" class="login-submit-btn" id="loginSubmitBtn">
          <span class="btn-spinner" aria-hidden="true"></span>
          <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
            <path d="M15 3h4a2 2 0 012 2v14a2 2 0 01-2 2h-4M10 17l5-5-5-5M15 12H3"/>
          </svg>
          <svg class="login-btn-check" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6L9 17l-5-5"/></svg>
          <span class="login-btn-label">ورود</span>
        </button>
      </form>

      <a href="/" class="login-back-link">بازگشت به داشبورد</a>

    </div>
  </main>

  <!-- ظرف Toast صفحه ورود -->
  <div class="login-toast-wrap" id="loginToastWrap" aria-live="assertive"></div>

  <script src="/assets/js/field.js?v=<?= $v_field ?>"></script>
  <script src="/assets/js/login.js?v=<?= $v_loginjs ?>"></script>

</body>
</html>
