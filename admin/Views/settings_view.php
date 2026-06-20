<?php
// ═══════════════════════════════════════════════════════════
// View: settings_view.php — تنظیمات ایمیل/SMTP و زمان‌بندی کد
// ═══════════════════════════════════════════════════════════
$s = $settings ?? [];
$val = fn(string $k) => htmlspecialchars((string) ($s[$k] ?? ''), ENT_QUOTES);
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>تنظیمات ایمیل — پنل مدیریت</title>
  <script>
    (function(){
      const t = localStorage.getItem('theme');
      const d = window.matchMedia('(prefers-color-scheme: dark)').matches;
      if (t === 'dark' || (!t && d)) document.documentElement.setAttribute('data-theme','dark');
    })();
  </script>
  <link rel="preload" href="/fonts/vazir-font/Vazir-Variable.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="stylesheet" href="/admin/assets/admin.css?v=<?= asset_v(__DIR__ . '/../../admin/assets/admin.css') ?>">
  <style>
    .settings-grid { display:grid; grid-template-columns:repeat(2,1fr); gap:14px 16px; }
    .settings-grid .full { grid-column:1 / -1; }
    @media (max-width:560px){ .settings-grid { grid-template-columns:1fr; } }
    .set-hint { font-size:12px; color:var(--text-3); margin-top:4px; line-height:1.6; }
    .set-switch { display:flex; align-items:center; gap:10px; cursor:pointer; }
    .set-switch-text { font-size:13.5px; color:var(--text); font-weight:500; }
    .set-section-title { font-family:'HeadingFont','DashboardFont',sans-serif; font-size:13px; font-weight:700; color:var(--text-2); margin:4px 0 2px; }
    /* خط جداکننده اضافه حذف شد: نوار بالا خودش border دارد، پس بخش اول بعد از آن نیازی به border-top ندارد */
    .topbar + .add-asset-form { border-top:none; padding-top:0; margin-top:18px; }
    /* کلید (toggle) هماهنگ با تم پروژه — همان استایل سوییچ‌های صفحه اعلان‌ها */
    .toggle-sw { position:relative; width:38px; height:22px; flex-shrink:0; display:inline-block; }
    .toggle-sw input { opacity:0; width:0; height:0; position:absolute; }
    .toggle-sw-track { position:absolute; inset:0; background:var(--border); border-radius:20px; cursor:pointer; transition:background var(--t); }
    .toggle-sw input:checked + .toggle-sw-track { background:var(--accent); }
    .toggle-sw input:focus-visible + .toggle-sw-track { box-shadow:0 0 0 3px var(--accent-bg); }
    .toggle-sw-track::after { content:''; position:absolute; top:2px; right:2px; width:18px; height:18px; border-radius:50%; background:#fff; transition:right var(--t); box-shadow:0 1px 3px rgba(0,0,0,.3); }
    .toggle-sw input:checked + .toggle-sw-track::after { right:18px; }
    /* ردیف ارسال ایمیل آزمایشی: ورودی + دکمه در یک خط، هم‌تراز پایین */
    .test-email-row { display:flex; align-items:flex-end; gap:10px; }
    .test-email-row .field { flex:1 1 auto; min-width:0; }
    .test-email-row .btn { flex:0 0 auto; }
    @media (max-width:560px){
      .test-email-row { flex-direction:column; align-items:stretch; }
      .test-email-row .btn { width:100%; justify-content:center; }
    }
  </style>
</head>
<body>

<div class="admin-wrap">

  <!-- ── نوار بالا ── -->
  <div class="topbar">
    <div class="topbar-title">تنظیمات ایمیل</div>
    <div style="display:flex;gap:10px;align-items:center;">
      <a href="/admin" class="btn-back" aria-label="بازگشت به پنل مدیریت">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5M12 5l-7 7 7 7"/></svg>
        <span class="btn-back-label">بازگشت</span>
      </a>
      <a href="/" class="btn btn-secondary btn-sm" style="text-decoration:none;">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/></svg>
        داشبورد
      </a>
    </div>
  </div>

  <!-- ── تنظیمات سرور ایمیل (SMTP) ── -->
  <div class="add-asset-form" style="margin-top:14px;">
    <h4>
      <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg>
      سرور ایمیل (SMTP)
    </h4>

    <div class="field full">
      <label class="set-switch">
        <span class="toggle-sw">
          <input type="checkbox" id="setSmtpEnabled" <?= ($s['smtp_enabled'] ?? '0') === '1' ? 'checked' : '' ?>>
          <span class="toggle-sw-track"></span>
        </span>
        <span class="set-switch-text">فعال‌سازی ارسال ایمیل از طریق SMTP</span>
      </label>
      <div class="set-hint">اگر غیرفعال باشد، در محیط محلی کد تایید به‌صورت آزمایشی در صفحه نمایش داده می‌شود.</div>
    </div>

    <div class="settings-grid" style="margin-top:12px;">
      <div class="field">
        <label>آدرس سرور (Host)</label>
        <input type="text" id="setSmtpHost" value="<?= $val('smtp_host') ?>" placeholder="smtp.gmail.com" dir="ltr" style="direction:ltr;text-align:left">
      </div>
      <div class="field">
        <label>پورت</label>
        <input type="text" id="setSmtpPort" value="<?= $val('smtp_port') ?>" placeholder="587" dir="ltr" style="direction:ltr;text-align:left">
      </div>
      <div class="field">
        <label>نوع رمزنگاری</label>
        <select id="setSmtpSecure">
          <option value="tls"  <?= ($s['smtp_secure'] ?? '') === 'tls'  ? 'selected' : '' ?>>STARTTLS (پورت ۵۸۷)</option>
          <option value="ssl"  <?= ($s['smtp_secure'] ?? '') === 'ssl'  ? 'selected' : '' ?>>SSL/TLS (پورت ۴۶۵)</option>
          <option value="none" <?= ($s['smtp_secure'] ?? '') === 'none' ? 'selected' : '' ?>>بدون رمزنگاری</option>
        </select>
      </div>
      <div class="field">
        <label>نام کاربری</label>
        <input type="text" id="setSmtpUser" value="<?= $val('smtp_user') ?>" placeholder="you@gmail.com" dir="ltr" style="direction:ltr;text-align:left" autocomplete="off">
      </div>
      <div class="field">
        <label>رمز عبور</label>
        <div class="pass-wrap">
          <input type="password" id="setSmtpPass" placeholder="<?= ($s['smtp_pass'] ?? '') !== '' ? '••••••• (برای حفظ، خالی بگذارید)' : 'رمز یا App Password' ?>" dir="ltr" style="direction:ltr;text-align:left" autocomplete="new-password">
          <button type="button" class="pass-toggle" aria-label="نمایش/مخفی" onclick="togglePass('setSmtpPass', this)">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
          </button>
        </div>
      </div>
      <div class="field">
        <label>ایمیل فرستنده</label>
        <input type="email" id="setSmtpFromEmail" value="<?= $val('smtp_from_email') ?>" placeholder="no-reply@yoursite.com" dir="ltr" style="direction:ltr;text-align:left">
      </div>
      <div class="field full">
        <label>نام فرستنده</label>
        <input type="text" id="setSmtpFromName" value="<?= $val('smtp_from_name') ?>" placeholder="داشبورد ابزارها">
      </div>
    </div>

    <div class="set-section-title" style="margin-top:16px;">زمان‌بندی کد تایید</div>
    <div class="settings-grid">
      <div class="field">
        <label>فاصله ارسال مجدد کد (ثانیه)</label>
        <input type="text" id="setResendCooldown" value="<?= $val('resend_cooldown') ?>" placeholder="30" dir="ltr" style="direction:ltr;text-align:left">
        <div class="set-hint">بازه مجاز: ۱۰ تا ۶۰۰ ثانیه</div>
      </div>
      <div class="field">
        <label>مدت اعتبار کد (ثانیه)</label>
        <input type="text" id="setCodeTtl" value="<?= $val('code_ttl') ?>" placeholder="600" dir="ltr" style="direction:ltr;text-align:left">
        <div class="set-hint">بازه مجاز: ۶۰ تا ۸۶۴۰۰ ثانیه</div>
      </div>
    </div>

    <div style="margin-top:16px;">
      <button class="btn btn-primary btn-sm" onclick="SettingsManager.save()">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
        ذخیره تنظیمات
      </button>
    </div>
  </div>

  <!-- ── ارسال ایمیل آزمایشی ── -->
  <div class="add-asset-form" style="margin-top:16px;">
    <h4>
      <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 2 11 13"/><path d="M22 2 15 22l-4-9-9-4Z"/></svg>
      ارسال ایمیل آزمایشی
    </h4>
    <div class="set-hint" style="margin-bottom:12px;">ابتدا تنظیمات را ذخیره کنید، سپس یک ایمیل وارد کنید تا پیام آزمایشی برایش ارسال شود.</div>
    <div class="test-email-row">
      <div class="field">
        <label>ایمیل مقصد</label>
        <input type="email" id="setTestEmail" placeholder="test@example.com" dir="ltr" style="direction:ltr;text-align:left">
      </div>
      <button class="btn btn-secondary" onclick="SettingsManager.test()">
        <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 2 11 13"/><path d="M22 2 15 22l-4-9-9-4Z"/></svg>
        ارسال آزمایشی
      </button>
    </div>
  </div>

</div><!-- /admin-wrap -->

<div class="toast" id="toast">
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" id="toastIcon"></svg>
  <span id="toastMsg"></span>
</div>

<script>
  const CSRF_TOKEN = '<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>';
  window.CSRF_TOKEN = CSRF_TOKEN;
  // سازگاری با admin.js (در این صفحه استفاده نمی‌شوند)
  const TOOLS_RAW = []; const USERS_DATA = []; const tools = []; const ICONS_DATA = {}; const DECOS_DATA = {};
</script>
<script src="/admin/assets/admin.js?v=<?= asset_v(__DIR__ . '/../../admin/assets/admin.js') ?>"></script>
</body>
</html>
