<?php
require_once __DIR__ . '/version.php';
require_once __DIR__ . '/admin/Core/UserSession.php';

// اگر از قبل وارد شده، مستقیم به داشبورد
UserSession::start();
if (UserSession::check()) {
    header('Location: /');
    exit;
}

$v_css   = asset_v(__DIR__ . '/assets/css/style.css');
$v_theme = asset_v(__DIR__ . '/assets/js/theme.js');
$v_field = asset_v(__DIR__ . '/assets/js/field.js');
$v_loginjs = asset_v(__DIR__ . '/assets/js/login.js');

// تب پیش‌فرض (ورود یا ثبت‌نام) از روی پارامتر
$startMode = (($_GET['tab'] ?? '') === 'register') ? 'register' : 'login';
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
  <!-- توجه: روی صفحه ورود از speculationrules استفاده نمی‌کنیم؛ prerender داشبورد در حالت
       مهمان ساخته می‌شد و پس از ورود تا رفرش، حالت مهمان را نشان می‌داد. ورود → بارگذاری تازه احرازشده. -->
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
          <svg class="login-logo-ring" viewBox="0 0 48 48" fill="none" aria-hidden="true">
            <circle class="lr-track" cx="24" cy="24" r="22"/>
            <circle class="lr-fill" cx="24" cy="24" r="22" pathLength="100"/>
          </svg>
          <svg class="login-logo-lock" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <rect x="3" y="11" width="18" height="11" rx="2"/>
            <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
          </svg>
        </div>
      </div>

      <!-- تب‌ها -->
      <div class="login-tabs" id="loginTabs" data-mode="<?= $startMode ?>" role="tablist" aria-label="ورود یا ثبت‌نام">
        <button type="button" class="login-tab<?= $startMode === 'login' ? ' active' : '' ?>" id="tabLogin"
                role="tab" aria-controls="loginForm" aria-selected="<?= $startMode === 'login' ? 'true' : 'false' ?>">ورود</button>
        <button type="button" class="login-tab<?= $startMode === 'register' ? ' active' : '' ?>" id="tabRegister"
                role="tab" aria-controls="registerForm" aria-selected="<?= $startMode === 'register' ? 'true' : 'false' ?>">ثبت‌نام</button>
        <span class="login-tab-indicator" aria-hidden="true"></span>
      </div>

      <!-- ═══ فرم ورود ═══ -->
      <form class="login-card-body login-form" id="loginForm" autocomplete="on" novalidate
            <?= $startMode === 'register' ? 'hidden' : '' ?>>
        <div class="field" data-state="idle">
          <label class="field-label" for="loginUsername">نام کاربری یا ایمیل</label>
          <div class="field-box">
            <input type="text" id="loginUsername" name="username" class="field-input" placeholder="نام کاربری یا ایمیل"
                   autocomplete="username" dir="ltr" maxlength="190"
                   <?= $startMode === 'login' ? 'autofocus' : '' ?>>
            <span class="field-status" aria-hidden="true"></span>
          </div>
          <p class="field-msg" aria-live="polite"><span class="field-msg-icon" aria-hidden="true"></span><span class="field-msg-text"></span></p>
        </div>

        <div class="field" data-state="idle">
          <label class="field-label" for="loginPassword">رمز عبور</label>
          <div class="field-box">
            <input type="password" id="loginPassword" name="password" class="field-input" placeholder="رمز عبور"
                   autocomplete="current-password" dir="ltr" maxlength="128">
            <button type="button" class="login-pass-toggle" aria-label="نمایش/مخفی کردن رمز" onclick="togglePass('loginPassword', this)">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            </button>
          </div>
          <p class="field-msg" aria-live="polite"><span class="field-msg-icon" aria-hidden="true"></span><span class="field-msg-text"></span></p>
        </div>

        <button type="button" class="login-forgot-link" id="forgotLink">فراموشی رمز عبور؟</button>

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

      <!-- ═══ فرم فراموشی رمز عبور (سه‌مرحله‌ای: ایمیل → تایید کد → رمز جدید) ═══ -->
      <form class="login-card-body login-form" id="forgotForm" autocomplete="off" novalidate data-step="1" hidden>

        <!-- سربرگ صفحه بازیابی (جایگزین تب‌ها) با یک دکمه بازگشت هوشمند -->
        <div class="forgot-head">
          <button type="button" class="forgot-back-top" id="fpBack" aria-label="بازگشت به ورود" title="بازگشت به ورود">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>
          </button>
          <h2 class="forgot-title">بازیابی رمز عبور</h2>
        </div>

        <!-- مرحله ۱: ایمیل -->
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

        <!-- مرحله ۲: تایید کد -->
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

        <!-- مرحله ۳: رمز جدید (فقط پس از تایید کد) -->
        <div class="reg-step" data-step="3" hidden>
          <p class="reg-code-hint">کد تایید شد. رمز جدید خود را تعیین کنید.</p>
          <div class="field" data-state="idle">
            <label class="field-label" for="fpPassword">رمز عبور جدید</label>
            <div class="field-box">
              <input type="password" id="fpPassword" name="password" class="field-input" placeholder="رمز عبور جدید"
                     autocomplete="new-password" dir="ltr" maxlength="128"
                     oninput="updateRegStrength(this.value)">
              <button type="button" class="login-pass-gen" aria-label="تولید رمز تصادفی" title="تولید رمز تصادفی" onclick="generatePassword('fpPassword','fpConfirm')">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9.94 15.5A2 2 0 0 0 8.5 14.06l-5.14-1.32a.5.5 0 0 1 0-.97L8.5 10.44A2 2 0 0 0 9.94 9l1.32-5.14a.5.5 0 0 1 .97 0L13.56 9A2 2 0 0 0 15 10.44l5.14 1.32a.5.5 0 0 1 0 .97L15 14.06a2 2 0 0 0-1.44 1.44l-1.32 5.14a.5.5 0 0 1-.97 0z"/><path d="M20 3v4M22 5h-4M4 17v2M5 18H3"/></svg>
              </button>
              <button type="button" class="login-pass-toggle" aria-label="نمایش/مخفی کردن رمز" onclick="togglePass('fpPassword', this)">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
              </button>
            </div>
            <p class="field-msg" aria-live="polite"><span class="field-msg-icon" aria-hidden="true"></span><span class="field-msg-text"></span></p>
          </div>
          <div class="field" data-state="idle">
            <label class="field-label" for="fpConfirm">تکرار رمز عبور</label>
            <div class="field-box">
              <input type="password" id="fpConfirm" name="confirm_password" class="field-input" placeholder="تکرار رمز عبور"
                     autocomplete="new-password" dir="ltr" maxlength="128">
              <button type="button" class="login-pass-toggle" aria-label="نمایش/مخفی کردن رمز" onclick="togglePass('fpConfirm', this)">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
              </button>
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

        <!-- نشانگر مرحله (مانند فرم ثبت‌نام) -->
        <div class="reg-footer">
          <div class="reg-progress" aria-hidden="true">
            <span class="reg-seg active"></span>
            <span class="reg-seg"></span>
            <span class="reg-seg"></span>
          </div>
          <div class="reg-step-label">مرحله <span id="fpStepNum">۱</span> از ۳</div>
        </div>
      </form>

      <!-- ═══ فرم ثبت‌نام (سه‌مرحله‌ای) ═══ -->
      <form class="login-card-body login-form login-register" id="registerForm" autocomplete="on" novalidate data-step="1"
            <?= $startMode === 'register' ? '' : 'hidden' ?>>

        <!-- مرحله ۱: حساب (نام + نام خانوادگی + ایمیل) — کامپوننتِ یکپارچهٔ .field -->
        <div class="reg-step" data-step="1">
          <div class="field" data-state="idle">
            <label class="field-label" for="regFullName">نام و نام خانوادگی</label>
            <div class="field-box">
              <input type="text" id="regFullName" name="full_name" class="field-input" placeholder="نام شما"
                     autocomplete="name" maxlength="60"
                     <?= $startMode === 'register' ? 'autofocus' : '' ?>>
              <span class="field-status" aria-hidden="true"></span>
            </div>
            <p class="field-msg" aria-live="polite"><span class="field-msg-icon" aria-hidden="true"></span><span class="field-msg-text"></span></p>
          </div>
          <div class="field" data-state="idle">
            <label class="field-label" for="regEmail">ایمیل</label>
            <div class="field-box">
              <input type="email" id="regEmail" name="email" class="field-input" placeholder="you@example.com"
                     autocomplete="email" dir="ltr" maxlength="190">
              <span class="field-status" aria-hidden="true"></span>
            </div>
            <p class="field-msg" aria-live="polite"><span class="field-msg-icon" aria-hidden="true"></span><span class="field-msg-text"></span></p>
          </div>
        </div>

        <!-- مرحله ۲: رمز عبور -->
        <div class="reg-step" data-step="2" hidden>
          <div class="field" data-state="idle">
            <label class="field-label" for="regPassword">رمز عبور</label>
            <div class="field-box">
              <input type="password" id="regPassword" name="password" class="field-input" placeholder="رمز عبور"
                     autocomplete="new-password" dir="ltr" maxlength="128"
                     oninput="updateRegStrength(this.value)">
              <button type="button" class="login-pass-gen" aria-label="تولید رمز تصادفی و قوی" title="تولید رمز تصادفی و قوی" onclick="generatePassword()">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                  <path d="M9.94 15.5A2 2 0 0 0 8.5 14.06l-5.14-1.32a.5.5 0 0 1 0-.97L8.5 10.44A2 2 0 0 0 9.94 9l1.32-5.14a.5.5 0 0 1 .97 0L13.56 9A2 2 0 0 0 15 10.44l5.14 1.32a.5.5 0 0 1 0 .97L15 14.06a2 2 0 0 0-1.44 1.44l-1.32 5.14a.5.5 0 0 1-.97 0z"/>
                  <path d="M20 3v4M22 5h-4M4 17v2M5 18H3"/>
                </svg>
              </button>
              <button type="button" class="login-pass-toggle" aria-label="نمایش/مخفی کردن رمز" onclick="togglePass('regPassword', this)">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
              </button>
            </div>
            <p class="field-msg" aria-live="polite"><span class="field-msg-icon" aria-hidden="true"></span><span class="field-msg-text"></span></p>
          </div>
          <div class="field" data-state="idle">
            <label class="field-label" for="regConfirm">تکرار رمز عبور</label>
            <div class="field-box">
              <input type="password" id="regConfirm" name="confirm_password" class="field-input" placeholder="تکرار رمز عبور"
                     autocomplete="new-password" dir="ltr" maxlength="128">
              <button type="button" class="login-pass-toggle" aria-label="نمایش/مخفی کردن رمز" onclick="togglePass('regConfirm', this)">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
              </button>
            </div>
            <p class="field-msg" aria-live="polite"><span class="field-msg-icon" aria-hidden="true"></span><span class="field-msg-text"></span></p>
          </div>
        </div>

        <!-- مرحله ۳: تایید ایمیل -->
        <div class="reg-step" data-step="3" hidden>
          <p class="reg-code-hint">کد ۶ رقمی به <b id="regEmailEcho" dir="ltr"></b> ارسال شد؛ آن را وارد کنید.</p>
          <input type="text" id="regCode" class="reg-code-input" inputmode="numeric" maxlength="6"
                 placeholder="------" autocomplete="one-time-code" dir="ltr" aria-label="کد تایید">
          <button type="button" class="reg-resend" id="regResend">
            <span class="reg-resend-spin" aria-hidden="true"></span>
            <span class="reg-resend-label">ارسال مجدد کد</span>
            <span id="regResendTimer"></span>
          </button>
          <p class="reg-dev-note" id="regDevNote" hidden></p>
        </div>

        <p class="login-error" id="registerError" aria-live="polite"></p>

        <!-- ناوبری مراحل -->
        <div class="reg-nav">
          <button type="button" class="reg-back-btn" id="regBackBtn" aria-label="مرحله قبلی" title="مرحله قبلی" hidden>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>
          </button>
          <button type="submit" class="login-submit-btn" id="registerSubmitBtn">
            <span class="btn-spinner" aria-hidden="true"></span>
            <svg class="login-btn-check" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6L9 17l-5-5"/></svg>
            <span class="login-btn-label">ادامه</span>
          </button>
        </div>

        <!-- نشانگر مرحله (مطابق تصویر) -->
        <div class="reg-footer">
          <div class="reg-progress" aria-hidden="true">
            <span class="reg-seg active"></span>
            <span class="reg-seg"></span>
            <span class="reg-seg"></span>
          </div>
          <div class="reg-step-label">مرحله <span id="regStepNum">۱</span> از ۳</div>
        </div>
      </form>

      <a href="/" class="login-back-link">بازگشت به داشبورد</a>

    </div>
  </main>

  <!-- ظرف Toast صفحه ورود/ثبت‌نام -->
  <div class="login-toast-wrap" id="loginToastWrap" aria-live="assertive"></div>

  <script src="/assets/js/field.js?v=<?= $v_field ?>"></script>
  <script src="/assets/js/login.js?v=<?= $v_loginjs ?>"></script>

</body>
</html>
