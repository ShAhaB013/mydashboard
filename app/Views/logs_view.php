<?php
// ═══════════════════════════════════════════════════════════
// View: logs_view.php — error/debug log viewer (list/filter/search/delete/clear)
// ═══════════════════════════════════════════════════════════
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>گزارش خطاها — پنل مدیریت</title>
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
  <style>
    /* ── Debug-mode toggle (same widget as the settings page's SMTP switch) ── */
    .set-hint { font-size:12px; color:var(--text-3); margin-top:4px; line-height:1.6; }
    .set-switch { display:flex; align-items:center; gap:10px; cursor:pointer; }
    .set-switch-text { font-size:13.5px; color:var(--text); font-weight:500; }
    .set-toggle-box {
      background:var(--bg-card); border:1px solid var(--border); border-radius:var(--radius-lg);
      padding:14px 16px; margin-bottom:14px;
    }
    .toggle-sw { position:relative; width:38px; height:22px; flex-shrink:0; display:inline-block; }
    .toggle-sw input { opacity:0; width:0; height:0; position:absolute; }
    .toggle-sw-track { position:absolute; inset:0; background:var(--border); border-radius:var(--radius-pill); cursor:pointer; transition:background var(--t); }
    .toggle-sw input:checked + .toggle-sw-track { background:var(--accent); }
    .toggle-sw input:focus-visible + .toggle-sw-track { box-shadow:0 0 0 3px var(--accent-bg); }
    .toggle-sw-track::after { content:''; position:absolute; top:2px; right:2px; width:18px; height:18px; border-radius:50%; background:#fff; transition:right var(--t); box-shadow:0 1px 3px rgba(0,0,0,.3); }
    .toggle-sw input:checked + .toggle-sw-track::after { right:18px; }

    /* ── Level badge (shared coloring: table cells, chips, detail modal) ── */
    .log-level { display:inline-flex; align-items:center; gap:5px; font-size:11px; font-weight:700;
      padding:3px 9px; border-radius:var(--radius-xs); line-height:1.6; flex-shrink:0; white-space:nowrap; }
    .log-level::before { content:''; width:6px; height:6px; border-radius:50%; background:currentColor; flex-shrink:0; }
    .log-level.error   { background:var(--danger-bg);  color:#f85149; border:1px solid rgba(248,81,73,.3); }
    .log-level.warning { background:rgba(217,119,6,.12); color:#d97706; border:1px solid rgba(217,119,6,.3); }
    .log-level.info    { background:rgba(88,166,255,.12); color:#58a6ff; border:1px solid rgba(88,166,255,.3); }
    .log-level.debug   { background:rgba(127,127,127,.12); color:var(--text-3); border:1px solid var(--border); }

    /* ── Toolbar: level chips + search ── */
    .log-toolbar { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:14px; flex-wrap:wrap; }
    .log-chips { display:flex; gap:7px; flex-wrap:wrap; }
    .log-chip {
      display:inline-flex; align-items:center; gap:6px; font-size:12px; font-weight:700;
      padding:6px 12px; border-radius:var(--radius-xs); cursor:pointer; background:none;
      transition:transform var(--t-bounce), filter var(--t); font-family:'DashboardFont',sans-serif;
    }
    .log-chip:hover { filter:brightness(1.08); }
    .log-chip:active { transform:scale(.96); }
    .log-chip:disabled { opacity:.5; cursor:not-allowed; filter:none; transform:none; }
    .log-chip .log-chip-count { font-variant-numeric:tabular-nums; opacity:.85; }
    .log-chip.active { color:#fff; border-color:transparent; }
    .log-chip.active::before { background:#fff; }
    .log-chip.all           { background:var(--bg-input); color:var(--text-2); border:1px solid var(--border); }
    .log-chip.all.active    { background:var(--accent); color:#fff; }
    .log-chip.all::before   { display:none; }
    .log-chip.error.active   { background:#f85149; }
    .log-chip.warning.active { background:#d97706; }
    .log-chip.info.active    { background:#58a6ff; }
    .log-chip.debug.active   { background:var(--text-3); }

    .log-search { position:relative; flex:1 1 240px; max-width:320px; margin-bottom:0; }
    .log-search-icon {
      position:absolute; top:50%; right:13px; transform:translateY(-50%);
      width:16px; height:16px; color:var(--text-3); pointer-events:none;
    }
    .log-search input {
      width:100%; box-sizing:border-box;
      font-family:'DashboardFont',sans-serif; font-size:13px;
      background:var(--bg-card); color:var(--text);
      border:1px solid var(--border); border-radius:var(--radius-xs);
      padding:9px 38px 9px 34px; outline:none;
      transition:border-color var(--t), box-shadow var(--t);
    }
    .log-search input:focus { border-color:var(--border-focus); box-shadow:0 0 0 3px var(--accent-bg); }
    .log-search input:disabled { opacity:.5; cursor:not-allowed; }
    .log-search-clear {
      position:absolute; top:50%; left:8px; transform:translateY(-50%);
      width:22px; height:22px; border-radius:50%; border:none;
      background:var(--bg-card); color:var(--text-3); cursor:pointer;
      display:none; align-items:center; justify-content:center;
      transition:background var(--t), color var(--t);
    }
    .log-search-clear svg { width:12px; height:12px; }
    .log-search-clear:hover { background:var(--danger-bg); color:var(--danger); }
    .log-search.has-value .log-search-clear { display:flex; }

    /* ── Advanced filter (date range) — same pattern as the notifications page ── */
    .log-adv-toggle {
      display:inline-flex; align-items:center; gap:6px; flex-shrink:0;
      font-family:'DashboardFont',sans-serif; font-size:13px; font-weight:600;
      padding:0 16px; height:38px; cursor:pointer; white-space:nowrap;
      color:var(--text-2); background:var(--bg-card);
      border:1px solid var(--border); border-radius:var(--radius-xs);
      transition:border-color var(--t), color var(--t), background var(--t);
    }
    .log-adv-toggle svg { width:15px; height:15px; flex-shrink:0; }
    .log-adv-toggle:hover { border-color:var(--accent); color:var(--accent); }
    .log-adv-toggle.active, .log-adv-toggle.has-filters { border-color:var(--accent); color:var(--accent); background:var(--accent-bg); }
    .log-adv-toggle:disabled { opacity:.5; cursor:not-allowed; }
    .log-adv-panel {
      display:none; flex-wrap:wrap; gap:14px; align-items:flex-end;
      margin-bottom:14px; padding:16px 18px;
      background:var(--bg-card); border:1px solid var(--border); border-radius:var(--radius-lg);
    }
    .log-adv-panel.open { display:flex; }
    .log-adv-field { display:flex; flex-direction:column; gap:6px; flex:1; min-width:150px; }
    .log-adv-field label { font-size:12px; font-weight:600; color:var(--text-2); }
    .log-adv-field .tdp-trigger { min-height:42px; border-radius:var(--radius-xs); }
    .log-adv-actions { display:flex; gap:8px; align-items:center; }
    .log-adv-actions .btn { height:42px; border-radius:var(--radius-xs); }
    @media (max-width:560px) { .log-adv-field { min-width:120px; } .log-adv-actions { width:100%; justify-content:flex-end; } }

    /* ── Table ── */
    .log-table { background:var(--bg-card); border:1px solid var(--border); border-radius:var(--radius-lg); overflow:hidden; }
    .log-table-head, .log-table-row {
      display:grid; grid-template-columns:110px 150px 1fr 46px; gap:14px; align-items:center;
      padding:11px 16px;
    }
    .log-table-head {
      font-size:11px; font-weight:700; color:var(--text-3); letter-spacing:.3px;
      border-bottom:1px solid var(--border); background:var(--bg-hover);
    }
    .log-sort-btn {
      display:inline-flex; align-items:center; gap:4px; background:none; border:none; padding:0;
      font-family:'DashboardFont',sans-serif; font-size:11px; font-weight:700; letter-spacing:.3px;
      color:var(--text-3); cursor:pointer; transition:color var(--t);
    }
    .log-sort-btn:hover, .log-sort-btn.active { color:var(--text); }
    .log-sort-btn svg { width:11px; height:11px; opacity:.4; transition:transform var(--t), opacity var(--t); }
    .log-sort-btn.active svg { opacity:1; }
    .log-sort-btn.active.dir-asc svg { transform:rotate(180deg); }
    .log-sort-btn:disabled { opacity:.4; cursor:not-allowed; }
    .log-table-row { border-bottom:1px solid var(--border); cursor:pointer; transition:background var(--t); }
    .log-table-row:last-child { border-bottom:none; }
    .log-table-row:hover { background:var(--bg-hover); }
    .log-table-time { font-size:12px; color:var(--text-3); font-family:monospace; direction:ltr; text-align:left; }
    .log-table-msg { font-family:'DashboardFont',sans-serif; font-size:13px; color:var(--text);
      overflow:hidden; text-overflow:ellipsis; white-space:nowrap; min-width:0; }
    .log-table-del {
      width:30px; height:30px; border-radius:var(--radius-xs); border:none; cursor:pointer;
      background:none; color:var(--text-3); display:flex; align-items:center; justify-content:center;
      transition:background var(--t), color var(--t); flex-shrink:0;
    }
    .log-table-del:hover { background:var(--danger-bg); color:var(--danger); }
    .log-table-del svg { width:15px; height:15px; }
    .log-empty { padding:44px 16px; text-align:center; color:var(--text-3); font-size:13.5px; }
    @media (max-width:640px) {
      .log-table-head { display:none; }
      .log-table-row { grid-template-columns:1fr 30px; grid-template-areas:"level del" "msg msg" "time time"; row-gap:6px; }
      .log-table-row .log-level { grid-area:level; }
      .log-table-del { grid-area:del; }
      .log-table-msg { grid-area:msg; white-space:normal; }
      .log-table-time { grid-area:time; text-align:right; }
    }

    /* ── Detail modal ── */
    .log-detail-modal { max-width:620px; }
    .log-detail-body { display:block; padding:20px 22px 8px; overflow-y:auto;
      user-select:text; -webkit-user-select:text; }
    .log-detail-top { display:flex; align-items:center; justify-content:space-between; gap:10px; margin-bottom:14px; }
    .log-detail-time { font-size:12px; color:var(--text-3); font-family:monospace; direction:ltr;
      user-select:text; -webkit-user-select:text; }
    .log-detail-msg {
      font-family:'DashboardFont',sans-serif; font-size:13.5px; color:var(--text); line-height:1.85;
      background:var(--bg-input); border:1px solid var(--border); border-radius:var(--radius-xs);
      padding:12px 14px; margin-bottom:16px; word-break:break-word; white-space:pre-wrap;
      user-select:text; -webkit-user-select:text; cursor:text;
    }
    .log-detail-meta { display:grid; grid-template-columns:repeat(auto-fit,minmax(190px,1fr)); gap:12px 16px; margin-bottom:6px; }
    .log-detail-meta-item { display:flex; flex-direction:column; gap:3px; min-width:0; }
    .log-detail-meta-label { font-size:10.5px; font-weight:700; color:var(--text-3); letter-spacing:.4px; }
    .log-detail-meta-value { font-size:12.5px; color:var(--text); font-family:monospace; direction:ltr; text-align:left;
      word-break:break-all; user-select:text; -webkit-user-select:text; cursor:text; }
    .log-detail-trace { margin-top:14px; padding-top:14px; border-top:1px solid var(--border); }
    .log-detail-trace-btn {
      display:flex; align-items:center; gap:6px; font-size:12.5px; font-weight:700; color:var(--accent);
      background:none; border:none; cursor:pointer; padding:4px 0; font-family:'DashboardFont',sans-serif;
    }
    .log-detail-trace-btn svg { width:12px; height:12px; transition:transform var(--t); }
    .log-detail-trace-btn.open svg { transform:rotate(180deg); }
    .log-detail-pre { direction:ltr; text-align:left; font-family:monospace; font-size:11.5px; white-space:pre-wrap;
      word-break:break-all; background:var(--bg-input); border:1px solid var(--border);
      border-radius:var(--radius-xs); padding:12px; max-height:280px; overflow:auto; margin-top:10px;
      user-select:text; -webkit-user-select:text; cursor:text; }
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
      <h1 class="app-header__title">گزارش خطاها</h1>
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

  <div class="set-toggle-box">
    <label class="set-switch">
      <span class="toggle-sw">
        <input type="checkbox" id="setDebugMode" data-change="logDebugModeChange" <?= ($debugMode ?? '0') === '1' ? 'checked' : '' ?>>
        <span class="toggle-sw-track"></span>
      </span>
      <span class="set-switch-text">حالت دیباگ — نمایش جزئیات کامل خطا در پاسخ سرور</span>
    </label>
    <div class="set-hint">
      در حالت فعال، پیام خطا همراه با جزئیات کامل در پاسخ نمایش داده می‌شود — فقط برای محیط توسعه یا عیب‌یابی موقت استفاده شود، نه محیط نهایی.
      صرف‌نظر از این تنظیم، جزئیات کامل هر خطا همیشه در همین صفحه ثبت می‌شود.
    </div>
  </div>

  <div class="log-toolbar">
    <div class="log-chips" id="logChips"></div>
    <div class="log-search">
      <svg class="log-search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
      </svg>
      <input type="text" id="logSearchInput" placeholder="جستجو در پیام خطا..." data-input="logSearch" autocomplete="off">
      <button type="button" class="log-search-clear" id="logSearchClear" data-act="logClearSearch" title="پاک کردن">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
          <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
        </svg>
      </button>
    </div>
    <button type="button" class="log-adv-toggle" id="logAdvToggle" data-act="logToggleAdvanced"
            aria-expanded="false" aria-controls="logAdvPanel" title="فیلتر بازه زمانی">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
        <line x1="4" y1="6" x2="20" y2="6"/><line x1="7" y1="12" x2="17" y2="12"/><line x1="10" y1="18" x2="14" y2="18"/>
      </svg>
      <span>فیلتر تاریخ</span>
    </button>
    <button type="button" class="btn btn-danger btn-sm" id="logClearBtn" data-act="logOpenClear" disabled aria-disabled="true">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
        <polyline points="3 6 5 6 21 6"/>
        <path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/>
      </svg>
      پاک‌سازی لاگ‌ها
    </button>
  </div>

  <div class="log-adv-panel" id="logAdvPanel">
    <div class="log-adv-field">
      <label for="log-df">از تاریخ</label>
      <input type="date" id="log-df" dir="ltr" class="datetime-ltr" data-input="logDateFieldChange" data-change="logDateFieldChange">
    </div>
    <div class="log-adv-field">
      <label for="log-dt">تا تاریخ</label>
      <input type="date" id="log-dt" dir="ltr" class="datetime-ltr" data-input="logDateFieldChange" data-change="logDateFieldChange">
    </div>
    <div class="log-adv-actions">
      <button type="button" class="btn btn-primary btn-sm" id="logApplyBtn" data-act="logApplyFilters" disabled aria-disabled="true">اعمال</button>
      <button type="button" class="btn btn-secondary btn-sm" id="logResetBtn" data-act="logResetFilters" disabled aria-disabled="true">حذف فیلترها</button>
    </div>
  </div>

  <div class="log-table">
    <div class="log-table-head">
      <button type="button" class="log-sort-btn" id="logSortLevel" data-act="logSortBy" data-key="level">
        سطح
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="6 9 12 15 18 9"/></svg>
      </button>
      <button type="button" class="log-sort-btn active dir-desc" id="logSortTime" data-act="logSortBy" data-key="created_at">
        زمان
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="6 9 12 15 18 9"/></svg>
      </button>
      <span>پیام</span><span></span>
    </div>
    <div id="logList"></div>
  </div>

  <div class="pagination" id="logPagination" style="display:none;"></div>
  <div class="pagination-info" id="logPageInfo"></div>

</div><!-- /admin-wrap -->

<!-- Detail modal (read-only) -->
<div class="modal-overlay" id="logDetailModal" role="dialog" aria-modal="true" aria-labelledby="logDetailTitle">
  <div class="modal log-detail-modal">
    <div class="modal-head">
      <h3 id="logDetailTitle">جزئیات لاگ</h3>
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
      <div class="log-detail-trace" id="logDetailTraceBlock" hidden>
        <button type="button" class="log-detail-trace-btn" data-act="logToggleTrace">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
          نمایش ردپای کامل (Stack Trace)
        </button>
        <pre class="log-detail-pre" id="logDetailTrace" hidden></pre>
      </div>
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
<script src="/assets/admin/logs-admin.js?v=<?= asset_v(__DIR__ . '/../../assets/admin/logs-admin.js') ?>"></script>
</body>
</html>
