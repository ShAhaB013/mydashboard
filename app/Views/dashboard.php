<?php
// ═══════════════════════════════════════════════════════════
// View: dashboard.php — admin dashboard
// ═══════════════════════════════════════════════════════════
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>پنل مدیریت ابزارها</title>
  <script nonce="<?= csp_nonce() ?>">
    (function(){
      const t = localStorage.getItem('theme');
      const d = window.matchMedia('(prefers-color-scheme: dark)').matches;
      if (t === 'dark' || (!t && d)) document.documentElement.setAttribute('data-theme','dark');
    })();
  </script>
  <link rel="preload" href="/fonts/vazir-font/Vazir-Variable.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="stylesheet" href="/assets/admin/admin.css?v=<?= asset_v(__DIR__ . '/../../assets/admin/admin.css') ?>">
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
    <h1 class="app-header__title">پنل مدیریت ابزارها</h1>
    <div class="app-header__actions">
      <a href="/" class="hdr-btn" title="بازگشت به داشبورد" aria-label="بازگشت به داشبورد">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M19 12H5M12 5l-7 7 7 7"/>
        </svg>
      </a>
    </div>
  </div>
</header>

<div class="admin-wrap">

  <div class="admin-tiles">

    <a href="/admin?page=users" class="admin-tile">
      <span class="admin-tile-ic">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/>
          <circle cx="9" cy="7" r="4"/>
          <path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/>
        </svg>
      </span>
      <span class="admin-tile-info">
        <span class="admin-tile-title">مدیریت کاربران</span>
        <span class="admin-tile-count"><b><?= (int) ($usersTotal ?? 0) ?></b> کاربر</span>
      </span>
      <svg class="admin-tile-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 18l-6-6 6-6"/></svg>
    </a>

    <a href="/admin?page=notifications" class="admin-tile">
      <span class="admin-tile-ic">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
          <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
          <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
        </svg>
      </span>
      <span class="admin-tile-info">
        <span class="admin-tile-title">مدیریت اعلان‌ها</span>
        <span class="admin-tile-count">ارسال و مدیریت اعلان‌های کاربران</span>
      </span>
      <svg class="admin-tile-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 18l-6-6 6-6"/></svg>
    </a>

    <a href="/admin?page=settings" class="admin-tile">
      <span class="admin-tile-ic">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>
        </svg>
      </span>
      <span class="admin-tile-info">
        <span class="admin-tile-title">تنظیمات ایمیل</span>
        <span class="admin-tile-count">سرور SMTP و زمان‌بندی کد OTP</span>
      </span>
      <svg class="admin-tile-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 18l-6-6 6-6"/></svg>
    </a>

    <a href="/admin?page=categories" class="admin-tile">
      <span class="admin-tile-ic">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M20.59 13.41 11 3.83A2 2 0 0 0 9.59 3.17L4 3a1 1 0 0 0-1 1l.17 5.59a2 2 0 0 0 .66 1.41l9.58 9.58a2 2 0 0 0 2.83 0l4.35-4.34a2 2 0 0 0 0-2.83z"/>
          <circle cx="7.5" cy="7.5" r="1.5"/>
        </svg>
      </span>
      <span class="admin-tile-info">
        <span class="admin-tile-title">مدیریت دسته‌بندی‌ها</span>
        <span class="admin-tile-count">تغییر نام و حذف دسته‌بندی‌های بدون ابزار</span>
      </span>
      <svg class="admin-tile-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 18l-6-6 6-6"/></svg>
    </a>

    <button type="button" class="admin-tile" data-act="togglePanel" data-panel="iconsBox">
      <span class="admin-tile-ic">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <rect x="3" y="3" width="18" height="18" rx="2"/>
          <circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/>
        </svg>
      </span>
      <span class="admin-tile-info">
        <span class="admin-tile-title">مدیریت آیکون‌ها</span>
        <span class="admin-tile-count"><b id="iconCountBadge"><?= count($icons) ?></b> آیکون</span>
      </span>
      <svg class="admin-tile-arrow admin-tile-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
    </button>

    <button type="button" class="admin-tile" data-act="togglePanel" data-panel="decosBox">
      <span class="admin-tile-ic">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>
        </svg>
      </span>
      <span class="admin-tile-info">
        <span class="admin-tile-title">مدیریت انیمیشن‌های کارت</span>
        <span class="admin-tile-count"><b id="decoCountBadge"><?= count($decosData) ?></b> انیمیشن</span>
      </span>
      <svg class="admin-tile-arrow admin-tile-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
    </button>

  </div>

  <div class="section-panel" id="iconsBox">
    <div class="section-panel-body">
      <div class="asset-grid" id="iconAssetGrid"></div>
      <div class="asset-editor" id="iconEditor" style="display:none;">
        <div class="asset-editor-head">
          <strong>آیکون انتخاب‌شده:</strong>
          <span class="key-badge" id="iconEditorKey"></span>
        </div>
        <div class="field">
          <label>SVG Path</label>
          <textarea id="iconEditorPath" rows="4" placeholder='<path d="..." fill="currentColor"/>'></textarea>
        </div>
        <div class="asset-editor-actions">
          <button class="btn btn-success btn-sm" data-act="saveIconEdit">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
            ذخیره تغییرات
          </button>
          <button class="btn btn-danger btn-sm" id="iconDeleteBtn" data-act="deleteIcon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <polyline points="3 6 5 6 21 6"/>
              <path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/>
            </svg>
            حذف آیکون
          </button>
        </div>
      </div>
      <div class="add-asset-form" id="iconAddForm" style="display:none;">
        <h4>
          <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5">
            <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
          </svg>
          آیکون جدید
        </h4>
        <div class="add-asset-row">
          <div class="field">
            <label>نام (key) <span class="req">*</span></label>
            <input type="text" id="newIconKey" placeholder="مثال: compress" maxlength="40">
          </div>
          <div class="field">
            <label>SVG Path <span class="req">*</span></label>
            <textarea id="newIconPath" rows="3" placeholder='<path d="M12 2..." fill="currentColor"/>'></textarea>
          </div>
        </div>
        <div style="display:flex;justify-content:flex-end;margin-top:10px;">
          <button class="btn btn-primary btn-sm" data-act="addNewIcon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
            افزودن آیکون
          </button>
        </div>
      </div>
    </div>
  </div>

  <div class="section-panel" id="decosBox">
    <div class="section-panel-body">
      <div class="asset-grid" id="decoAssetGrid" style="grid-template-columns:repeat(auto-fill,minmax(90px,1fr));"></div>
      <div class="asset-editor" id="decoEditor" style="display:none;">
        <div class="asset-editor-head">
          <strong>انیمیشن انتخاب‌شده:</strong>
          <span class="key-badge" id="decoEditorKey"></span>
        </div>
        <div class="field">
          <label>SVG کامل</label>
          <textarea id="decoEditorSVG" rows="8" placeholder='<svg class="card-deco" viewBox="0 0 120 60" ...>'></textarea>
        </div>
        <div style="margin-top:10px;">
          <div style="font-size:12px;color:var(--text-2);margin-bottom:6px;">پیش‌نمایش:</div>
          <div id="decoEditorPreview" style="width:100%;max-width:280px;height:72px;border-radius:var(--radius-xs);background:rgba(88,166,255,.05);border:1px solid var(--border);overflow:hidden;--card-color:#58a6ff;"></div>
        </div>
        <div class="asset-editor-actions">
          <button class="btn btn-success btn-sm" data-act="saveDecoEdit">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
            ذخیره تغییرات
          </button>
          <button class="btn btn-secondary btn-sm" data-act="refreshDecoPreview">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <polyline points="1 4 1 10 7 10"/>
              <path d="M3.51 15a9 9 0 102.13-9.36L1 10"/>
            </svg>
            پیش‌نمایش
          </button>
          <button class="btn btn-danger btn-sm" id="decoDeleteBtn" data-act="deleteDeco">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <polyline points="3 6 5 6 21 6"/>
              <path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/>
            </svg>
            حذف
          </button>
        </div>
      </div>
      <div class="add-asset-form" id="decoAddForm" style="display:none;">
        <h4>
          <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5">
            <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
          </svg>
          انیمیشن جدید
        </h4>
        <div class="add-asset-row">
          <div class="field">
            <label>نام (key) <span class="req">*</span></label>
            <input type="text" id="newDecoKey" placeholder="مثال: waves" maxlength="40">
          </div>
          <div class="field">
            <label>SVG کامل <span class="req">*</span></label>
            <textarea id="newDecoSVG" rows="5" placeholder='<svg class="card-deco" viewBox="0 0 120 60" aria-hidden="true">...</svg>'></textarea>
          </div>
        </div>
        <div style="display:flex;justify-content:flex-end;margin-top:10px;">
          <button class="btn btn-primary btn-sm" data-act="addNewDeco">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
            افزودن انیمیشن
          </button>
        </div>
      </div>
    </div>
  </div>

</div><!-- /admin-wrap -->

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

<!-- PHP data passed to JS -->
<script nonce="<?= csp_nonce() ?>">
  const CSRF_TOKEN = '<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>';
  window.CSRF_TOKEN = CSRF_TOKEN; // needed to send the X-CSRF-Token header in admin.js
  // "Lite" version of all tools (id/title/badge/iconKey/deco/is_public) — for
  // sorting, the access modal, and icon/deco counts. The full card list is
  // paginated server-side (ToolsView → list_tools). TOOLS_RAW is the same lite array.
  const tools      = <?= $toolsLite ?>;
  const TOOLS_RAW  = tools;
  window.tools     = tools;
  const TOOLS_TOTAL = <?= (int) ($toolsTotal ?? 0) ?>;
  const ICONS_DATA = <?= $iconsJson ?>;
  const DECOS_DATA = <?= $decosJson ?>;
</script>
<script src="/assets/js/tooltip.js?v=<?= asset_v(__DIR__ . '/../../assets/js/tooltip.js') ?>" defer></script>
<script src="/assets/js/actions.js?v=<?= asset_v(__DIR__ . '/../../assets/js/actions.js') ?>"></script>
<script src="/assets/js/password-policy.js?v=<?= asset_v(__DIR__ . '/../../assets/js/password-policy.js') ?>"></script>
<script src="/assets/admin/admin.js?v=<?= asset_v(__DIR__ . '/../../assets/admin/admin.js') ?>"></script>

</body>
</html>