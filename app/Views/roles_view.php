<?php
// ═══════════════════════════════════════════════════════════
// View: roles_view.php — access roles (WHMCS-style): each role = categories + tools + menus,
// every user has exactly one role (assigned in the user modal on the users page)
// ═══════════════════════════════════════════════════════════
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>نقش‌های دسترسی — پنل مدیریت</title>
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
      <h1 class="app-header__title">نقش‌های دسترسی</h1>
      <span class="app-header__count" id="roleCountBadge">0</span>
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

  <div class="role-intro">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
    <span>
      هر نقش مشخص می‌کند اعضایش کدام دسته‌ها، ابزارها و منوها را ببینند. هر کاربر دقیقا یک نقش دارد
      که در صفحه «<a href="/admin?page=users" class="role-intro-link">مدیریت کاربران</a>» انتخاب می‌شود؛ با ویرایش نقش، دسترسی همه اعضا یکجا تغییر می‌کند.
    </span>
  </div>

  <div class="role-toolbar">
    <div class="role-search" id="roleSearchWrap">
      <svg class="role-search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
      </svg>
      <input type="text" id="roleSearchInput" placeholder="جستجو در نام نقش، اعضا، دسته‌ها و ابزارها..."
             data-input="roleSearch" autocomplete="off">
      <button type="button" class="role-search-clear" data-act="roleClearSearch" title="پاک کردن">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
          <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
        </svg>
      </button>
    </div>
    <button type="button" class="btn btn-primary btn-sm" data-act="roleOpenAdd">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
      نقش جدید
    </button>
  </div>

  <div class="role-list" id="roleList"></div>

</div><!-- /admin-wrap -->

<!-- Add/edit role modal -->
<div class="modal-overlay" id="roleModal" role="dialog" aria-modal="true" aria-labelledby="roleModalTitle">
  <div class="modal role-modal">
    <div class="modal-head">
      <h3 id="roleModalTitle">نقش جدید</h3>
      <button class="modal-close" data-act="roleClose" aria-label="بستن">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
          <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
        </svg>
      </button>
    </div>
    <div class="modal-body role-modal-body">
      <input type="hidden" id="roleId" value="">

      <div class="access-section">
        <div class="field">
          <label for="roleName">نام نقش <span class="req">*</span></label>
          <div class="field-input-wrap">
            <input type="text" id="roleName" maxlength="60" placeholder="مثال: پشتیبانی" data-input="roleCount">
            <span class="field-counter-inline" id="roleNameCounter" dir="ltr"><span id="roleNameCount">0</span>/60</span>
          </div>
        </div>
        <div class="field">
          <label for="roleDescription">توضیحات <span class="opt">(اختیاری)</span></label>
          <div class="field-input-wrap">
            <input type="text" id="roleDescription" maxlength="255" placeholder="این نقش برای چه کسانی است؟" data-input="roleCount">
            <span class="field-counter-inline" id="roleDescriptionCounter" dir="ltr"><span id="roleDescriptionCount">0</span>/255</span>
          </div>
        </div>
      </div>

      <div class="access-section">
        <div class="role-search">
          <svg class="role-search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
          </svg>
          <!-- Filter only: excluded from the modal's dirty-state tracking -->
          <input type="text" id="roleFilter" placeholder="فیلتر دسته‌ها و ابزارها..." data-input="roleFilter" autocomplete="off">
          <button type="button" class="role-search-clear" data-act="roleClearFilter" title="پاک کردن">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
              <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
            </svg>
          </button>
        </div>

        <div class="access-section-title">
          <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M20.59 13.41l-7.17 7.17a2 2 0 01-2.83 0L2 12V2h10l8.59 8.59a2 2 0 010 2.82z"/>
            <line x1="7" y1="7" x2="7.01" y2="7"/>
          </svg>
          دسته‌بندی‌ها
          <span class="role-badges-summary" id="roleBadgesSummary"></span>
        </div>
        <div class="access-badges-grid" id="roleBadgesGrid"></div>
      </div>

      <div class="access-section">
        <div class="access-section-title">
          <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2">
            <rect x="2" y="3" width="20" height="14" rx="2"/>
            <line x1="8" y1="21" x2="16" y2="21"/>
            <line x1="12" y1="17" x2="12" y2="21"/>
          </svg>
          ابزارهای خاص
          <span class="role-tools-summary" id="roleToolsSummary"></span>
        </div>
        <div class="access-tools-list" id="roleToolsList"></div>
      </div>

      <div class="access-section">
        <div class="access-section-title">
          <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
            <line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/>
          </svg>
          منوها
        </div>
        <div class="set-toggle-box">
          <label class="set-switch">
            <span class="toggle-sw">
              <input type="checkbox" id="roleShowProfile" checked>
              <span class="toggle-sw-track"></span>
            </span>
            <span class="set-switch-text">نمایش «حساب کاربری» (شامل نشست‌های فعال)</span>
          </label>
          <label class="set-switch">
            <span class="toggle-sw">
              <input type="checkbox" id="roleShowNotifications" checked>
              <span class="toggle-sw-track"></span>
            </span>
            <span class="set-switch-text">نمایش «اعلان‌ها»</span>
          </label>
        </div>
      </div>
    </div>
    <div class="modal-foot">
      <button class="btn btn-secondary btn-sm" data-act="roleClose">انصراف</button>
      <button class="btn btn-primary btn-sm" id="roleSaveBtn" data-act="roleSave">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
        ذخیره نقش
      </button>
    </div>
  </div>
</div>

<!-- Confirm modal (delete / unsaved changes) -->
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
  // The role modal's tool picker needs "all tools" → lite version (id/title/badge)
  const TOOLS_RAW  = <?= $toolsLite ?>;
  const tools      = TOOLS_RAW;
  // Tools-dashboard variables aren't used on this page but are defined for compatibility with admin.js
  const ICONS_DATA = {};
  const DECOS_DATA = {};
</script>
<script src="/assets/js/tooltip.js?v=<?= asset_v(__DIR__ . '/../../assets/js/tooltip.js') ?>" defer></script>
<script src="/assets/js/actions.js?v=<?= asset_v(__DIR__ . '/../../assets/js/actions.js') ?>"></script>
<script src="/assets/admin/admin.js?v=<?= asset_v(__DIR__ . '/../../assets/admin/admin.js') ?>"></script>
<script src="/assets/admin/roles-admin.js?v=<?= asset_v(__DIR__ . '/../../assets/admin/roles-admin.js') ?>"></script>
</body>
</html>
