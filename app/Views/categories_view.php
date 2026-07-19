<?php
// ═══════════════════════════════════════════════════════════
// View: categories_view.php — category management (rename / delete)
// ═══════════════════════════════════════════════════════════
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>مدیریت دسته‌بندی‌ها — پنل مدیریت</title>
  <script nonce="<?= csp_nonce() ?>">
    (function(){
      const t = localStorage.getItem('theme');
      const d = window.matchMedia('(prefers-color-scheme: dark)').matches;
      if (t === 'dark' || (!t && d)) document.documentElement.setAttribute('data-theme','dark');
    })();
  </script>
  <link rel="preload" href="/fonts/vazir-font/Vazir-Variable.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="stylesheet" href="/assets/admin/admin.css?v=<?= asset_v(__DIR__ . '/../../assets/admin/admin.css') ?>">
  <style>
    .set-hint { font-size:12px; color:var(--text-3); margin-top:4px; line-height:1.6; }
  </style>
  <!-- Preload internal pages for fast navigation (on hover/click intent) -->
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
</head>
<body>

<header class="app-header">
  <div class="app-header__inner">
    <div class="app-header__lead">
      <h1 class="app-header__title">دسته‌بندی‌ها</h1>
      <span class="app-header__count" id="categoryCountBadge">0</span>
    </div>
    <div class="app-header__actions">
      <a href="/" class="hdr-btn" title="داشبورد" aria-label="داشبورد">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/></svg>
      </a>
      <a href="/admin" class="hdr-btn" title="بازگشت به پنل مدیریت" aria-label="بازگشت به پنل مدیریت">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5M12 5l-7 7 7 7"/></svg>
      </a>
    </div>
  </div>
</header>

<div class="admin-wrap">

  <p class="set-hint" style="margin-bottom:14px;">
    دسته‌بندی‌ها از طریق فرم افزودن/ویرایش ابزار در داشبورد ساخته می‌شوند. این صفحه فقط برای تغییر نام یا حذف
    دسته‌بندی‌های موجود است — حذف تنها زمانی ممکن است که دیگر هیچ ابزاری از آن دسته استفاده نکند.
  </p>

  <div class="settings-card" style="padding:0;">
    <div id="categoryList"></div>
  </div>

</div><!-- /admin-wrap -->

<!-- Rename modal -->
<div class="modal-overlay" id="categoryRenameModal" role="dialog" aria-modal="true" aria-labelledby="categoryRenameTitle">
  <div class="modal rename-modal">
    <div class="modal-head">
      <h3 id="categoryRenameTitle">تغییر نام دسته‌بندی</h3>
      <button class="modal-close" data-act="catCloseRename" aria-label="بستن">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
          <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
        </svg>
      </button>
    </div>
    <div class="modal-body rename-modal-body">
      <input type="hidden" id="catRenameId" value="">
      <div class="field">
        <label for="catRenameName">نام جدید <span class="req">*</span></label>
        <div class="field-input-wrap">
          <input type="text" id="catRenameName" maxlength="20" data-keydown="catRenameKey">
          <span class="field-counter-inline" id="catRenameCounter" dir="ltr"><span id="catRenameCount">0</span>/20</span>
        </div>
        <div class="field-hint">فقط حروف فارسی/انگلیسی و _ (underscore) مجاز است</div>
      </div>
    </div>
    <div class="modal-foot">
      <button class="btn btn-secondary btn-sm" data-act="catCloseRename">انصراف</button>
      <button class="btn btn-primary btn-sm" data-act="catSaveRename">ذخیره</button>
    </div>
  </div>
</div>

<!-- Confirm modal (delete) -->
<div class="modal-overlay" id="confirmModal" role="dialog" aria-modal="true" aria-labelledby="confirmTitle">
  <div class="modal confirm-modal">
    <div class="modal-head">
      <h3 id="confirmTitle">تاییدیه</h3>
      <button class="modal-close" data-act="closeConfirm" aria-label="بستن">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
          <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
        </svg>
      </button>
    </div>
    <div class="modal-body">
      <div class="confirm-icon" id="confirmIcon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
          <polyline points="3 6 5 6 21 6"/>
          <path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/>
          <path d="M10 11v6M14 11v6M9 6V4a1 1 0 011-1h4a1 1 0 011 1v2"/>
        </svg>
      </div>
      <h4 id="confirmHeading"></h4>
      <p class="confirm-desc" id="confirmBody"></p>
      <div class="confirm-warn" id="confirmWarn"></div>
    </div>
    <div class="modal-foot">
      <button class="btn btn-secondary btn-sm" id="confirmCancelBtn" data-act="closeConfirm">انصراف</button>
      <button class="btn btn-sm" id="confirmActionBtn" data-act="runConfirm">تایید</button>
    </div>
  </div>
</div>

<!-- Toast (content built by JS) -->
<div class="toast" id="toast" aria-live="assertive"></div>

<script nonce="<?= csp_nonce() ?>">
  const CSRF_TOKEN = '<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>';
  window.CSRF_TOKEN = CSRF_TOKEN;
  // Tools-dashboard variables aren't used on this page but are defined for compatibility with admin.js
  const TOOLS_RAW  = [];
  const tools      = TOOLS_RAW;
  const ICONS_DATA = {};
  const DECOS_DATA = {};
</script>
<script src="/assets/js/tooltip.js?v=<?= asset_v(__DIR__ . '/../../assets/js/tooltip.js') ?>" defer></script>
<script src="/assets/js/actions.js?v=<?= asset_v(__DIR__ . '/../../assets/js/actions.js') ?>"></script>
<script src="/assets/admin/admin.js?v=<?= asset_v(__DIR__ . '/../../assets/admin/admin.js') ?>"></script>
</body>
</html>
