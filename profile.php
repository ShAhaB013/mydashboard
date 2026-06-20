<?php
require_once __DIR__ . '/version.php';
$v_css   = asset_v(__DIR__ . '/assets/css/style.css');
$v_js    = asset_v(__DIR__ . '/assets/js/script.js');
$v_theme = asset_v(__DIR__ . '/assets/js/theme.js');
$v_profilecss = asset_v(__DIR__ . '/assets/css/profile.css');
$v_profilejs  = asset_v(__DIR__ . '/assets/js/profile.js');
$v_field      = asset_v(__DIR__ . '/assets/js/field.js');
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
  <!-- پیش‌بارگذاری صفحات داخلی برای ناوبری سریع (هنگام hover/قصد کلیک) -->
  <script type="speculationrules">
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

  <!-- ── هدر ── -->
  <header role="banner">
    <div class="header-container">
      <h1>داشبورد مجموعه ابزارهای کمکی</h1>
      <div class="header-actions">
        <a href="/" class="btn-back" aria-label="بازگشت به داشبورد">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M19 12H5M12 5l-7 7 7 7"/>
          </svg>
          <span class="btn-back-label">بازگشت به داشبورد</span>
        </a>
      </div>
    </div>
  </header>

  <main class="profile-main" role="main">

    <div class="profile-card">

      <!-- هدر کارت -->
      <div class="profile-card-head">
        <div class="profile-avatar" id="profileAvatar">؟</div>
        <div class="profile-card-head-info">
          <h2 id="profileDisplayName">در حال بارگذاری...</h2>
          <div class="profile-meta-row">
            <span class="profile-meta-val profile-email" id="profileEmail" dir="ltr"></span>
          </div>
        </div>
      </div>

      <!-- بدنه کارت -->
      <div class="profile-card-body">

        <!-- ── تغییر ایمیل ── -->
        <div class="profile-section-title">تغییر ایمیل</div>

        <div class="field" data-state="idle">
          <label class="field-label" for="newEmail">ایمیل جدید</label>
          <div class="field-box">
            <input type="email" id="newEmail" class="field-input" placeholder="you@example.com"
                   dir="ltr" autocomplete="email" maxlength="190">
            <span class="field-status" aria-hidden="true"></span>
          </div>
          <p class="field-msg" aria-live="polite"><span class="field-msg-icon" aria-hidden="true"></span><span class="field-msg-text"></span></p>
          <div class="field-hint">برای تغییر ایمیل، یک کد تایید به ایمیل جدید ارسال می‌شود.</div>
        </div>

        <!-- مرحله کد تایید (پنهان تا ارسال کد) -->
        <div class="field" id="emailCodeField" data-state="idle" style="display:none;">
          <label class="field-label" for="emailCode">کد تایید</label>
          <div class="field-box">
            <input type="text" id="emailCode" class="field-input profile-code-input" placeholder="------"
                   dir="ltr" inputmode="numeric" maxlength="6" autocomplete="one-time-password">
          </div>
          <div class="field-hint">
            کد به <span id="emailCodeTarget" dir="ltr"></span> ارسال شد.
            <button type="button" id="emailResendBtn" class="profile-link-btn" onclick="resendEmailCode()" disabled><span class="profile-resend-spin" aria-hidden="true"></span><span class="profile-resend-label">ارسال مجدد</span></button>
            <span class="profile-hint-sep">·</span>
            <button type="button" class="profile-link-btn profile-cancel-btn" onclick="cancelEmailChange()">لغو</button>
          </div>
        </div>

        <!-- یادداشت کد آزمایشی (فقط محیط محلی) -->
        <p class="reg-dev-note" id="emailDevNote" style="display:none;"></p>

        <!-- پیام خطا / موفقیت ایمیل -->
        <div class="profile-msg" id="emailMsg" role="alert" aria-live="polite">
          <svg id="emailMsgIcon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"></svg>
          <span id="emailMsgText"></span>
        </div>

        <button class="profile-submit-btn" id="emailSubmitBtn" onclick="submitEmailChange()">
          <span class="btn-spinner" aria-hidden="true"></span>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
            <path d="M22 2 11 13"/><path d="M22 2 15 22l-4-9-9-4Z"/>
          </svg>
          <span id="emailSubmitLabel">ارسال کد تایید</span>
        </button>

        <div class="profile-divider"></div>

        <div class="profile-section-title">تغییر رمز عبور</div>

        <!-- رمز فعلی -->
        <div class="field" data-state="idle">
          <label class="field-label" for="currentPassword">رمز عبور فعلی</label>
          <div class="field-box">
            <input type="password" id="currentPassword" class="field-input" placeholder="رمز عبور فعلی"
                   autocomplete="current-password" maxlength="128">
            <button type="button" class="profile-pass-toggle" aria-label="نمایش/مخفی کردن رمز" onclick="togglePass('currentPassword', this)">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                <circle cx="12" cy="12" r="3"/>
              </svg>
            </button>
          </div>
          <p class="field-msg" aria-live="polite"><span class="field-msg-icon" aria-hidden="true"></span><span class="field-msg-text"></span></p>
        </div>

        <div class="profile-divider"></div>

        <!-- رمز جدید -->
        <div class="field" data-state="idle">
          <label class="field-label" for="newPassword">رمز عبور جدید</label>
          <div class="field-box">
            <input type="password" id="newPassword" class="field-input" placeholder="حداقل ۶ کاراکتر"
                   autocomplete="new-password" maxlength="128" oninput="checkStrength(this.value)">
            <button type="button" class="profile-pass-toggle" aria-label="نمایش/مخفی کردن رمز" onclick="togglePass('newPassword', this)">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                <circle cx="12" cy="12" r="3"/>
              </svg>
            </button>
          </div>
          <p class="field-msg" aria-live="polite"><span class="field-msg-icon" aria-hidden="true"></span><span class="field-msg-text"></span></p>
          <!-- نوار قدرت رمز -->
          <div class="pass-strength" id="passStrength" style="display:none;">
            <div class="pass-strength-bar"></div>
            <div class="pass-strength-bar"></div>
            <div class="pass-strength-bar"></div>
            <div class="pass-strength-bar"></div>
          </div>
          <div class="pass-strength-label" id="passStrengthLabel"></div>
        </div>

        <!-- تکرار رمز جدید -->
        <div class="field" data-state="idle">
          <label class="field-label" for="confirmPassword">تکرار رمز عبور جدید</label>
          <div class="field-box">
            <input type="password" id="confirmPassword" class="field-input" placeholder="تکرار رمز عبور جدید"
                   autocomplete="new-password" maxlength="128">
            <button type="button" class="profile-pass-toggle" aria-label="نمایش/مخفی کردن رمز" onclick="togglePass('confirmPassword', this)">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                <circle cx="12" cy="12" r="3"/>
              </svg>
            </button>
          </div>
          <p class="field-msg" aria-live="polite"><span class="field-msg-icon" aria-hidden="true"></span><span class="field-msg-text"></span></p>
        </div>

        <!-- پیام خطا / موفقیت -->
        <div class="profile-msg" id="profileMsg" role="alert" aria-live="polite">
          <svg id="profileMsgIcon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"></svg>
          <span id="profileMsgText"></span>
        </div>

        <!-- دکمه ذخیره -->
        <button class="profile-submit-btn" id="profileSubmitBtn" onclick="submitChangePassword()">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
            <polyline points="20 6 9 17 4 12"/>
          </svg>
          ذخیره رمز عبور جدید
        </button>

      </div>
    </div>

  </main>

  <footer class="app-footer">
    <span class="app-version" dir="ltr"><?= htmlspecialchars(app_version_label()) ?></span>
  </footer>

  <script src="/assets/js/field.js?v=<?= $v_field ?>"></script>
  <script src="/assets/js/profile.js?v=<?= $v_profilejs ?>"></script>

</body>
</html>
