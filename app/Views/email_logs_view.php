<?php
// ═══════════════════════════════════════════════════════════
// View: email_logs_view.php — outgoing email log (list/filter/search/delete/clear)
// ═══════════════════════════════════════════════════════════
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>گزارش ایمیل‌ها — پنل مدیریت</title>
  <script nonce="<?= csp_nonce() ?>">
    (function(){
      const t = localStorage.getItem('theme');
      const d = window.matchMedia('(prefers-color-scheme: dark)').matches;
      if (t === 'dark' || (!t && d)) document.documentElement.setAttribute('data-theme','dark');
    })();
  </script>
  <link rel="preload" href="/fonts/vazir-font/Vazir-Variable.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="stylesheet" href="/assets/admin/admin.css?v=<?= asset_v(__DIR__ . '/../../assets/admin/admin.css') ?>">
  <link rel="stylesheet" href="/assets/css/datepicker.css?v=<?= asset_v(__DIR__ . '/../../assets/css/datepicker.css') ?>">
  <link rel="stylesheet" href="/assets/css/pagination.css?v=<?= asset_v(__DIR__ . '/../../assets/css/pagination.css') ?>">
  <link rel="stylesheet" href="/assets/admin/logs.css?v=<?= asset_v(__DIR__ . '/../../assets/admin/logs.css') ?>">
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
      <h1 class="app-header__title">گزارش ایمیل‌ها</h1>
      <span class="app-header__count" id="logCountBadge">0</span>
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

  <div class="log-toolbar">
    <div class="log-chips" id="logChips"></div>
    <div class="log-search">
      <svg class="log-search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
      </svg>
      <input type="text" id="logSearchInput" placeholder="جستجوی گیرنده یا موضوع..." data-input="mailLogSearch" autocomplete="off">
      <button type="button" class="log-search-clear" id="logSearchClear" data-act="mailLogClearSearch" title="پاک کردن">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
          <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
        </svg>
      </button>
    </div>
    <button type="button" class="log-adv-toggle" id="logAdvToggle" data-act="mailLogToggleAdvanced"
            aria-expanded="false" aria-controls="logAdvPanel" title="فیلتر بازه زمانی">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
        <line x1="4" y1="6" x2="20" y2="6"/><line x1="7" y1="12" x2="17" y2="12"/><line x1="10" y1="18" x2="14" y2="18"/>
      </svg>
      <span>فیلتر تاریخ</span>
    </button>
    <button type="button" class="btn btn-danger btn-sm" id="logClearBtn" data-act="mailLogOpenClear" disabled aria-disabled="true">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
        <polyline points="3 6 5 6 21 6"/>
        <path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/>
      </svg>
      پاک‌سازی گزارش‌ها
    </button>
  </div>

  <div class="log-adv-panel" id="logAdvPanel">
    <div class="log-adv-field">
      <label for="log-df">از تاریخ</label>
      <input type="date" id="log-df" dir="ltr" class="datetime-ltr" data-input="mailLogDateFieldChange" data-change="mailLogDateFieldChange">
    </div>
    <div class="log-adv-field">
      <label for="log-dt">تا تاریخ</label>
      <input type="date" id="log-dt" dir="ltr" class="datetime-ltr" data-input="mailLogDateFieldChange" data-change="mailLogDateFieldChange">
    </div>
    <div class="log-adv-actions">
      <button type="button" class="btn btn-primary btn-sm" id="logApplyBtn" data-act="mailLogApplyFilters" disabled aria-disabled="true">اعمال</button>
      <button type="button" class="btn btn-secondary btn-sm" id="logResetBtn" data-act="mailLogResetFilters" disabled aria-disabled="true">حذف فیلترها</button>
    </div>
  </div>

  <div class="log-table mail-log-table">
    <div class="log-table-head">
      <span>وضعیت</span>
      <button type="button" class="log-sort-btn active dir-desc" id="logSortTime" data-act="mailLogToggleSort">
        زمان
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="6 9 12 15 18 9"/></svg>
      </button>
      <span>نوع ایمیل</span>
      <span>گیرنده و موضوع</span>
      <span></span>
    </div>
    <div id="logList"></div>
  </div>

  <div class="pagination" id="logPagination" style="display:none;"></div>
  <div class="pagination-info" id="logPageInfo"></div>

</div><!-- /admin-wrap -->

<!-- Detail modal (read-only) -->
<div class="modal-overlay" id="logDetailModal" role="dialog" aria-modal="true" aria-labelledby="logDetailTitle">
  <div class="modal log-detail-modal mail-log-detail">
    <div class="modal-head">
      <h3 id="logDetailTitle">جزئیات ارسال ایمیل</h3>
      <button class="modal-close" data-act="closeModal" data-modal="logDetailModal" aria-label="بستن">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
          <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
        </svg>
      </button>
    </div>
    <div class="modal-body log-detail-body">
      <div class="log-detail-top">
        <span class="log-level" id="logDetailLevel"></span>
        <span class="log-detail-time" id="logDetailTime"></span>
      </div>
      <div class="log-detail-msg" id="logDetailMsg"></div>
      <div class="log-detail-meta" id="logDetailMeta"></div>
      <p class="mail-log-hint" id="logDetailHint" hidden>
        «ارسال شده» یعنی سرور ایمیل پیام را تحویل گرفته است؛ اگر کاربر آن را دریافت نکرده، پوشه اسپم را بررسی کند.
        با «شناسه پیام» و «پاسخ سرور» می‌توانید از پشتیبانی هاست وضعیت تحویل را پیگیری کنید.
      </p>
    </div>
    <div class="modal-foot">
      <button class="btn btn-secondary btn-sm" data-act="closeModal" data-modal="logDetailModal">بستن</button>
    </div>
  </div>
</div>

<!-- Confirm modal (delete / clear) -->
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
<script src="/assets/js/datepicker.js?v=<?= asset_v(__DIR__ . '/../../assets/js/datepicker.js') ?>"></script>
<script src="/assets/admin/admin.js?v=<?= asset_v(__DIR__ . '/../../assets/admin/admin.js') ?>"></script>
<script src="/assets/admin/email-logs-admin.js?v=<?= asset_v(__DIR__ . '/../../assets/admin/email-logs-admin.js') ?>"></script>
</body>
</html>
