<?php
require_once __DIR__ . '/version.php';
// Shared bootstrap: autoload + config + DB + session
$config = require __DIR__ . '/bootstrap.php';

// If already logged in, go straight to the dashboard
if (UserSession::check()) {
    header('Location: /');
    exit;
}

$v_css   = asset_v(__DIR__ . '/assets/css/style.css');
$v_theme = asset_v(__DIR__ . '/assets/js/theme.js');
$v_field = asset_v(__DIR__ . '/assets/js/field.js');
$v_loginjs = asset_v(__DIR__ . '/assets/js/login.js');
$v_pwpolicy = asset_v(__DIR__ . '/assets/js/password-policy.js');
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
  <script nonce="<?= csp_nonce() ?>">
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

    <!-- Live background: soft animated gradient + floating bubbles (light and theme-aware) -->
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
            <!-- Outer ring in accent color -->
            <circle cx="50" cy="50" r="46" fill="none" stroke="url(#ll-ring)" stroke-width="3" opacity="0.85"/>
            <!-- Simple orbit dots in accent color -->
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
            <!-- 8-tooth gear in accent color (outer r=14, inner r=10) -->
            <path id="ll-gear"
                  d="M50,36 L53.8,40.8 L59.9,40.1 L59.2,46.2 L64,50 L59.2,53.8 L59.9,59.9 L53.8,59.2 L50,64 L46.2,59.2 L40.1,59.9 L40.8,53.8 L36,50 L40.8,46.2 L40.1,40.1 L46.2,40.8 Z"
                  fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>
            <!-- Central gear hub -->
            <circle cx="50" cy="50" r="6.5" fill="none" stroke="currentColor" stroke-width="2"/>
          </svg>
        </div>
        <h1 class="login-title">ورود به حساب کاربری</h1>
      </div>

      <!-- ═══ Login form ═══ -->
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
                   autocomplete="current-password" dir="ltr" maxlength="64">
            <button type="button" class="login-pass-toggle" aria-label="نمایش/مخفی کردن رمز" data-act="togglePass" data-target="loginPassword">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            </button>
          </div>
          <p class="field-msg" aria-live="polite"><span class="field-msg-icon" aria-hidden="true"></span><span class="field-msg-text"></span></p>
        </div>

        <button type="button" class="login-forgot-link" id="forgotLink">بازیابی رمز عبور</button>

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

      <!-- ═══ Forgot password form (three steps: email → verify code → new password) ═══ -->
      <form class="login-card-body login-form" id="forgotForm" autocomplete="off" novalidate data-step="1" hidden>

        <div class="forgot-head">
          <button type="button" class="forgot-back-top" id="fpBack" aria-label="بازگشت به ورود" title="بازگشت به ورود">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5M12 5l-7 7 7 7"/></svg>
          </button>
          <h2 class="forgot-title">بازیابی رمز عبور</h2>
        </div>

        <!-- Step 1: email -->
        <div class="reg-step" data-step="1">
          <p class="reg-code-hint">ایمیل حسابتان را وارد کنید تا کد بازیابی برایتان ارسال شود.</p>
          <div class="field" data-state="idle">
            <label class="field-label" for="fpEmail">ایمیل</label>
            <div class="field-box">
              <input type="email" id="fpEmail" name="email" class="field-input" placeholder="you@example.com"
                     autocomplete="email" dir="ltr" maxlength="190">
              <span class="field-status" aria-hidden="true"></span>
            </div>
            <p class="field-msg" aria-live="polite"><span class="field-msg-icon" aria-hidden="true"></span><span class="field-msg-text"></span></p>
          </div>
        </div>

        <!-- Step 2: verify code -->
        <div class="reg-step" data-step="2" hidden>
          <p class="reg-code-hint">کد ۶ رقمی به <b id="fpEmailEcho" dir="ltr"></b> ارسال شد؛ آن را وارد کنید.</p>
          <input type="text" id="fpCode" class="reg-code-input" inputmode="numeric" maxlength="6"
                 placeholder="------" autocomplete="one-time-code" dir="ltr" aria-label="کد بازیابی">
          <button type="button" class="reg-resend" id="fpResend">
            <span class="reg-resend-spin" aria-hidden="true"></span>
            <span class="reg-resend-label">ارسال مجدد کد</span>
            <span id="fpResendTimer"></span>
          </button>
          <p class="reg-dev-note" id="fpDevNote" hidden></p>
        </div>

        <!-- Step 3: new password (only after code verification) -->
        <div class="reg-step" data-step="3" hidden>
          <p class="reg-code-hint">کد تایید شد. رمز جدید خود را تعیین کنید.</p>
          <div class="field" data-state="idle">
            <label class="field-label" for="fpPassword">رمز عبور جدید</label>
            <div class="field-box field-box--counted" dir="ltr">
              <input type="password" id="fpPassword" name="password" class="field-input" placeholder="رمز عبور جدید"
                     autocomplete="new-password" dir="ltr" maxlength="64">
              <button type="button" class="login-pass-gen" aria-label="تولید رمز تصادفی" title="تولید رمز تصادفی" data-act="genPassword" data-target="fpPassword" data-confirm="fpConfirm">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9.94 15.5A2 2 0 0 0 8.5 14.06l-5.14-1.32a.5.5 0 0 1 0-.97L8.5 10.44A2 2 0 0 0 9.94 9l1.32-5.14a.5.5 0 0 1 .97 0L13.56 9A2 2 0 0 0 15 10.44l5.14 1.32a.5.5 0 0 1 0 .97L15 14.06a2 2 0 0 0-1.44 1.44l-1.32 5.14a.5.5 0 0 1-.97 0z"/><path d="M20 3v4M22 5h-4M4 17v2M5 18H3"/></svg>
              </button>
              <button type="button" class="profile-pass-toggle" aria-label="نمایش/مخفی کردن رمز" data-act="togglePass" data-target="fpPassword">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
              </button>
              <span class="field-counter-inline" id="fpPasswordCounter" dir="ltr"><span id="fpPasswordCount">0</span>/64</span>
            </div>
            <p class="field-msg" aria-live="polite"><span class="field-msg-icon" aria-hidden="true"></span><span class="field-msg-text"></span></p>
            <!-- Live password rules checklist (updates on focus/typing) -->
            <div class="pass-rules" id="fpPassRules" aria-live="polite" hidden>
              <div class="pass-rules-title">قوانین رمز عبور</div>
              <ul class="pass-rules-list">
                <li class="pass-rule" data-rule="len"><span class="pass-rule-ic" aria-hidden="true"></span><span class="pass-rule-txt">بین ۱۰ تا ۶۴ کاراکتر</span></li>
                <li class="pass-rule" data-rule="lower"><span class="pass-rule-ic" aria-hidden="true"></span><span class="pass-rule-txt">حداقل یک حرف کوچک انگلیسی (a-z)</span></li>
                <li class="pass-rule" data-rule="upper"><span class="pass-rule-ic" aria-hidden="true"></span><span class="pass-rule-txt">حداقل یک حرف بزرگ انگلیسی (A-Z)</span></li>
                <li class="pass-rule" data-rule="digit"><span class="pass-rule-ic" aria-hidden="true"></span><span class="pass-rule-txt">حداقل یک عدد</span></li>
                <li class="pass-rule" data-rule="special"><span class="pass-rule-ic" aria-hidden="true"></span><span class="pass-rule-txt">حداقل یک نماد (مانند ‎!@#$‎)</span></li>
              </ul>
            </div>
          </div>
          <div class="field" data-state="idle">
            <label class="field-label" for="fpConfirm">تکرار رمز عبور</label>
            <div class="field-box field-box--counted" dir="ltr">
              <input type="password" id="fpConfirm" name="confirm_password" class="field-input" placeholder="تکرار رمز عبور"
                     autocomplete="new-password" dir="ltr" maxlength="64">
              <button type="button" class="profile-pass-toggle" aria-label="نمایش/مخفی کردن رمز" data-act="togglePass" data-target="fpConfirm">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
              </button>
              <span class="field-counter-inline" id="fpConfirmCounter" dir="ltr"><span id="fpConfirmCount">0</span>/64</span>
            </div>
            <p class="field-msg" aria-live="polite"><span class="field-msg-icon" aria-hidden="true"></span><span class="field-msg-text"></span></p>
          </div>
        </div>

        <div class="reg-nav">
          <button type="submit" class="login-submit-btn" id="fpSubmitBtn">
            <span class="btn-spinner" aria-hidden="true"></span>
            <svg class="login-btn-check" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6L9 17l-5-5"/></svg>
            <span class="login-btn-label">ارسال کد</span>
          </button>
        </div>

        <div class="reg-footer">
          <div class="reg-progress" aria-hidden="true">
            <span class="reg-seg active"></span>
            <span class="reg-seg"></span>
            <span class="reg-seg"></span>
          </div>
          <div class="reg-step-label">مرحله <span id="fpStepNum">۱</span> از ۳</div>
        </div>
      </form>

    </div>
  </main>

  <!-- Login page toast container -->
  <div class="toast-wrap" id="toastWrap" aria-live="assertive"></div>

  <script src="/assets/js/field.js?v=<?= $v_field ?>"></script>
  <script src="/assets/js/actions.js?v=<?= asset_v(__DIR__ . '/assets/js/actions.js') ?>"></script>
  <script src="/assets/js/password-policy.js?v=<?= $v_pwpolicy ?>"></script>
  <script src="/assets/js/login.js?v=<?= $v_loginjs ?>"></script>

</body>
</html>
