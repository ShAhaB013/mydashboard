<?php
require_once __DIR__ . '/version.php';

// ── گارد سمت‌سرور: صفحه حساب فقط برای کاربر لاگین‌کرده ──
// بدون این، صفحه کامل رندر می‌شد و سپس JS ریدایرکت می‌کرد (فلش/پرش زشت).
// با ریدایرکت سمت‌سرور پیش از هر خروجی، مهمان اصلا صفحه را نمی‌بیند.
$config = require __DIR__ . '/bootstrap.php';
if (!UserSession::check()) {
    header('Location: /');
    exit;
}

// توکن CSRF برای درخواست‌های حالت‌تغییردهنده‌ی api.php (change_password / terminate_my_*)
$csrfToken = UserSession::ensureCsrfToken();

$v_css   = asset_v(__DIR__ . '/assets/css/style.css');
$v_js    = asset_v(__DIR__ . '/assets/js/script.js');
$v_theme = asset_v(__DIR__ . '/assets/js/theme.js');
$v_profilecss = asset_v(__DIR__ . '/assets/css/profile.css');
$v_profilejs  = asset_v(__DIR__ . '/assets/js/profile.js');
$v_field      = asset_v(__DIR__ . '/assets/js/field.js');
$v_pwpolicy   = asset_v(__DIR__ . '/assets/js/password-policy.js');
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <meta name="description" content="تنظیمات حساب کاربری">
  <meta name="theme-color" content="#3e7de7">
  <meta name="color-scheme" content="light dark">
  <title>تنظیمات حساب کاربری</title>
  <link rel="preload" href="fonts/vazir-font/Vazir-Variable.woff2" as="font" type="font/woff2" crossorigin="anonymous">
  <link rel="stylesheet" href="/assets/css/style.css?v=<?= $v_css ?>">
  <script src="/assets/js/theme.js?v=<?= $v_theme ?>" defer></script>
  <script src="/assets/js/tooltip.js?v=<?= asset_v(__DIR__ . '/assets/js/tooltip.js') ?>" defer></script>
  <!-- پیش‌بارگذاری صفحات داخلی برای ناوبری سریع (هنگام hover/قصد کلیک) -->
  <script type="speculationrules" nonce="<?= csp_nonce() ?>">
  {
    "prerender": [{
      "where": { "and": [
        { "href_matches": "/*" },
        { "not": { "href_matches": "*logout*" } },
        { "not": { "href_matches": "*api.php*" } },
        { "not": { "href_matches": "*action=*" } }
      ]},
      "eagerness": "moderate"
    }]
  }
  </script>
  <link rel="stylesheet" href="/assets/css/profile.css?v=<?= $v_profilecss ?>">
</head>
<body class="profile-wrap">

  <!-- ── هدر یکپارچه (سبک تلگرام) — نوار شناور ── -->
  <header class="app-header">
    <div class="app-header__inner">
      <div class="app-header__lead"><h1 class="app-header__title">حساب کاربری</h1></div>
      <div class="app-header__actions">
        <a href="/" class="hdr-btn" title="بازگشت به داشبورد" aria-label="بازگشت به داشبورد">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M19 12H5M12 5l-7 7 7 7"/>
          </svg>
        </a>
      </div>
    </div>
  </header>

  <main class="profile-main" role="main">

    <div class="profile-card">

      <!-- هدر کارت -->
      <div class="profile-card-head is-loading" id="profileCardHead">
        <div class="profile-avatar" id="profileAvatar">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        </div>
        <div class="profile-card-head-info">
          <div class="profile-name-row">
            <h2 id="profileDisplayName">در حال بارگذاری...</h2>
            <span class="profile-admin-badge" id="profileAdminBadge" title="مدیر سیستم" hidden>
              <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 1.5l2.6 1.9 3.2-.3 1 3 2.8 1.6-1 3.1 1 3.1-2.8 1.6-1 3-3.2-.3L12 21l-2.6-1.9-3.2.3-1-3-2.8-1.6 1-3.1-1-3.1L5.2 6l1-3 3.2.3z"/><path d="M9 12.2l2 2 4-4.4" fill="none" stroke="#fff" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </span>
          </div>
          <span class="profile-meta-val profile-email" id="profileEmail" dir="ltr"></span>
        </div>
      </div>

      <!-- بدنه کارت -->
      <div class="profile-card-body">

        <!-- ── نوار تب‌ها ── -->
        <div class="profile-tabs" id="profileTabs" role="tablist">
          <span class="profile-tab-indicator" id="profileTabIndicator" aria-hidden="true"></span>
          <button type="button" class="profile-tab active" role="tab" aria-selected="true"
                  aria-controls="tabPanel-name" id="tabBtn-name" data-tab="name">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            مشخصات کاربری
          </button>
          <button type="button" class="profile-tab" role="tab" aria-selected="false"
                  aria-controls="tabPanel-password" id="tabBtn-password" data-tab="password">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            تغییر رمز عبور
          </button>
          <button type="button" class="profile-tab" role="tab" aria-selected="false"
                  aria-controls="tabPanel-sessions" id="tabBtn-sessions" data-tab="sessions">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/></svg>
            نشست‌های فعال
          </button>
        </div>

        <!-- ── تب: مشخصات کاربری ── -->
        <div class="profile-tab-panel is-loading" id="tabPanel-name" role="tabpanel" aria-labelledby="tabBtn-name">
        <section class="profile-section">
          <div class="profile-section-aside">
            <div class="profile-section-title">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
              مشخصات کاربری
            </div>
            <p class="profile-section-desc">این نام صرفا در پروفایل نمایش داده می‌شود.</p>
          </div>
          <div class="profile-section-body">

        <!-- نام -->
        <div class="field" data-state="idle">
          <label class="field-label" for="firstName">نام</label>
          <div class="field-box">
            <input type="text" id="firstName" class="field-input" placeholder="نام" maxlength="60" autocomplete="given-name">
          </div>
          <p class="field-msg" aria-live="polite"><span class="field-msg-icon" aria-hidden="true"></span><span class="field-msg-text"></span></p>
        </div>

        <!-- نام‌خانوادگی -->
        <div class="field" data-state="idle">
          <label class="field-label" for="lastName">نام‌خانوادگی</label>
          <div class="field-box">
            <input type="text" id="lastName" class="field-input" placeholder="نام‌خانوادگی" maxlength="60" autocomplete="family-name">
          </div>
          <p class="field-msg" aria-live="polite"><span class="field-msg-icon" aria-hidden="true"></span><span class="field-msg-text"></span></p>
        </div>

        <!-- دکمه ویرایش -->
        <button class="profile-submit-btn" id="nameSubmitBtn" data-act="submitUpdateName">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/>
            <path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/>
          </svg>
          ویرایش نام و نام‌خانوادگی
        </button>
          </div>
        </section>
        </div>

        <!-- ── تب: تغییر رمز عبور ── -->
        <div class="profile-tab-panel" id="tabPanel-password" role="tabpanel" aria-labelledby="tabBtn-password" hidden>
        <section class="profile-section">
          <div class="profile-section-aside">
            <div class="profile-section-title">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
              تغییر رمز عبور
            </div>
            <p class="profile-section-desc">برای امنیت بیشتر، رمز عبورتان را به‌صورت دوره‌ای تغییر دهید.</p>
          </div>
          <div class="profile-section-body">

        <!-- رمز فعلی -->
        <div class="field" data-state="idle">
          <label class="field-label" for="currentPassword">رمز عبور فعلی</label>
          <div class="field-box" dir="ltr">
            <input type="password" id="currentPassword" class="field-input" placeholder="رمز عبور فعلی"
                   autocomplete="current-password" maxlength="128">
            <button type="button" class="profile-pass-toggle" aria-label="نمایش/مخفی کردن رمز" data-act="togglePass" data-target="currentPassword">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                <circle cx="12" cy="12" r="3"/>
              </svg>
            </button>
          </div>
          <p class="field-msg" aria-live="polite"><span class="field-msg-icon" aria-hidden="true"></span><span class="field-msg-text"></span></p>
        </div>

        <!-- رمز جدید -->
        <div class="field" data-state="idle">
          <label class="field-label" for="newPassword">رمز عبور جدید</label>
          <div class="field-box" dir="ltr">
            <input type="password" id="newPassword" class="field-input" placeholder="رمز عبور جدید"
                   autocomplete="new-password" maxlength="64">
            <button type="button" class="login-pass-gen" aria-label="تولید رمز تصادفی" title="تولید رمز تصادفی" data-act="genPassword" data-target="newPassword" data-confirm="confirmPassword">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9.94 15.5A2 2 0 0 0 8.5 14.06l-5.14-1.32a.5.5 0 0 1 0-.97L8.5 10.44A2 2 0 0 0 9.94 9l1.32-5.14a.5.5 0 0 1 .97 0L13.56 9A2 2 0 0 0 15 10.44l5.14 1.32a.5.5 0 0 1 0 .97L15 14.06a2 2 0 0 0-1.44 1.44l-1.32 5.14a.5.5 0 0 1-.97 0z"/><path d="M20 3v4M22 5h-4M4 17v2M5 18H3"/></svg>
            </button>
            <button type="button" class="profile-pass-toggle" aria-label="نمایش/مخفی کردن رمز" data-act="togglePass" data-target="newPassword">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                <circle cx="12" cy="12" r="3"/>
              </svg>
            </button>
          </div>
          <p class="field-msg" aria-live="polite"><span class="field-msg-icon" aria-hidden="true"></span><span class="field-msg-text"></span></p>
          <!-- چک‌لیست زنده‌ی قوانین رمز عبور (هنگام focus/تایپ به‌روز می‌شود) -->
          <div class="pass-rules" id="passRules" aria-live="polite" hidden>
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

        <!-- تکرار رمز جدید -->
        <div class="field" data-state="idle">
          <label class="field-label" for="confirmPassword">تکرار رمز عبور جدید</label>
          <div class="field-box" dir="ltr">
            <input type="password" id="confirmPassword" class="field-input" placeholder="تکرار رمز عبور جدید"
                   autocomplete="new-password" maxlength="128">
            <button type="button" class="profile-pass-toggle" aria-label="نمایش/مخفی کردن رمز" data-act="togglePass" data-target="confirmPassword">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                <circle cx="12" cy="12" r="3"/>
              </svg>
            </button>
          </div>
          <p class="field-msg" aria-live="polite"><span class="field-msg-icon" aria-hidden="true"></span><span class="field-msg-text"></span></p>
        </div>

        <!-- دکمه ذخیره -->
        <button class="profile-submit-btn" id="profileSubmitBtn" data-act="submitChangePassword">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
            <polyline points="20 6 9 17 4 12"/>
          </svg>
          ذخیره رمز عبور جدید
        </button>
          </div>
        </section>
        </div>

        <!-- ── تب: نشست‌های فعال (دستگاه‌ها) — مانند تلگرام ── -->
        <div class="profile-tab-panel" id="tabPanel-sessions" role="tabpanel" aria-labelledby="tabBtn-sessions" hidden>
        <section class="profile-section">
          <div class="profile-section-aside">
            <div class="profile-section-title">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/>
              </svg>
              نشست‌های فعال
            </div>
            <p class="profile-section-desc">دستگاه‌هایی که با حساب شما وارد شده‌اند. اگر نشستی را نمی‌شناسید، آن را ببندید.</p>
          </div>
          <div class="profile-section-body">
            <div id="acctSessionsList" class="acct-sessions">
              <div class="sk-list-item" aria-hidden="true">
                <div class="sk sk-list-icon"></div>
                <div class="sk-list-lines"><div class="sk sk-line"></div><div class="sk sk-line sk-line--short"></div></div>
              </div>
              <div class="sk-list-item" aria-hidden="true">
                <div class="sk sk-list-icon"></div>
                <div class="sk-list-lines"><div class="sk sk-line"></div><div class="sk sk-line sk-line--short"></div></div>
              </div>
            </div>
            <button class="profile-submit-btn acct-killall-btn" id="acctKillOthers" data-act="terminateMyOtherSessions" style="display:none;">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M18.36 6.64A9 9 0 1 1 5.64 17.36"/><line x1="12" y1="2" x2="12" y2="12"/>
              </svg>
              پایان همه نشست‌های دیگر
            </button>
          </div>
        </section>
        </div>

      </div>
    </div>

  </main>

  <!-- ظرف Toast صفحه پروفایل -->
  <div class="toast-wrap" id="toastWrap" aria-live="assertive"></div>

  <script nonce="<?= csp_nonce() ?>">window.CSRF_TOKEN = <?= json_encode($csrfToken, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>;</script>
  <script src="/assets/js/field.js?v=<?= $v_field ?>"></script>
  <script src="/assets/js/actions.js?v=<?= asset_v(__DIR__ . '/assets/js/actions.js') ?>"></script>
  <script src="/assets/js/password-policy.js?v=<?= $v_pwpolicy ?>"></script>
  <script src="/assets/js/profile.js?v=<?= $v_profilejs ?>"></script>

</body>
</html>
