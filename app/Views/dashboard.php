<?php
// ═══════════════════════════════════════════════════════════
// View: dashboard.php — داشبورد مدیریت
// ═══════════════════════════════════════════════════════════
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>پنل مدیریت ابزارها</title>
  <script>
    (function(){
      const t = localStorage.getItem('theme');
      const d = window.matchMedia('(prefers-color-scheme: dark)').matches;
      if (t === 'dark' || (!t && d)) document.documentElement.setAttribute('data-theme','dark');
    })();
  </script>
  <link rel="preload" href="/fonts/vazir-font/Vazir-Variable.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="stylesheet" href="/assets/admin/admin.css?v=<?= asset_v(__DIR__ . '/../../assets/admin/admin.css') ?>">
</head>
<body>

<!-- ── هدر یکپارچه (سبک تلگرام) ── -->
<header class="app-header">
  <div class="app-header__inner">
    <h1 class="app-header__title">پنل مدیریت ابزارها</h1>
    <div class="app-header__actions">
      <a href="/admin?page=notifications" class="hdr-btn" title="مدیریت اعلان‌ها" aria-label="مدیریت اعلان‌ها">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
          <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
          <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
        </svg>
      </a>
      <a href="/admin?page=settings" class="hdr-btn" title="تنظیمات ایمیل" aria-label="تنظیمات ایمیل">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>
        </svg>
      </a>
      <a href="/" class="hdr-btn" title="بازگشت به داشبورد" aria-label="بازگشت به داشبورد">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M19 12H5M12 5l-7 7 7 7"/>
        </svg>
      </a>
    </div>
  </div>
</header>

<div class="admin-wrap">

  <!-- ── مدیریت کاربران (صفحه مستقل) ── -->
  <div class="section-box" id="usersBox">
    <div class="section-box-head" style="cursor:default;">
      <h2>
        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/>
          <circle cx="9" cy="7" r="4"/>
          <path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/>
        </svg>
        مدیریت کاربران
        <span class="count-badge"><?= (int) ($usersTotal ?? 0) ?></span>
      </h2>
      <a href="/admin?page=users" class="btn btn-primary btn-sm" style="text-decoration:none;">
        مدیریت و جستجوی کاربران
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 18l-6-6 6-6"/></svg>
      </a>
    </div>
  </div>

  <!-- ── نشست‌های فعال کاربران ── -->
  <div class="section-box" id="sessionsBox">
    <div class="section-box-head" onclick="SessionsManager.toggleBox()">
      <h2>
        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
          <rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/>
        </svg>
        نشست‌های فعال
        <span class="count-badge" id="sessionsCountBadge">…</span>
      </h2>
      <svg class="chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <polyline points="6 9 12 15 18 9"/>
      </svg>
    </div>
    <div class="section-box-body">
      <div class="sess-toolbar">
        <p class="sess-hint">نشست‌های فعال همه کاربران روی دستگاه‌های مختلف. می‌توانید موارد غیرضروری را پایان دهید.</p>
        <div class="sess-toolbar-actions">
          <button class="btn btn-secondary btn-sm" onclick="SessionsManager.loadPanel()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M23 4v6h-6M1 20v-6h6"/><path d="M3.51 9a9 9 0 0114.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0020.49 15"/></svg>
            بروزرسانی
          </button>
          <button class="btn btn-danger btn-sm" onclick="SessionsManager.terminateOthers()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18.36 6.64A9 9 0 1 1 5.64 17.36"/><line x1="12" y1="2" x2="12" y2="12"/></svg>
            پایان همه نشست‌های دیگر
          </button>
        </div>
      </div>
      <div class="sess-ttl-row">
        <label for="sessTtlInput">مدت فعال‌بودن نشست هر ورود:</label>
        <input type="text" id="sessTtlInput" value="<?= (int) ($sessionTtlHours ?? 24) ?>" inputmode="numeric" maxlength="3" dir="ltr">
        <span>ساعت</span>
        <button class="btn btn-secondary btn-sm" onclick="SessionsManager.saveTtl()">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
          ذخیره
        </button>
        <span class="sess-ttl-hint">۱ تا ۷۲۰ ساعت — هر کاربر تا این مدت پس از آخرین فعالیت وارد می‌ماند.</span>
      </div>
      <div id="sessionsPanel" class="sess-list">
        <div class="blocks-loading">برای مشاهده، این بخش را باز کنید…</div>
      </div>
    </div>
  </div>

  <!-- ── مدیریت آیکون‌ها ── -->
  <div class="section-box" id="iconsBox">
    <div class="section-box-head" onclick="toggleBox('iconsBox')">
      <h2>
        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
          <rect x="3" y="3" width="18" height="18" rx="2"/>
          <circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/>
        </svg>
        مدیریت آیکون‌ها
        <span class="count-badge" id="iconCountBadge"><?= count($icons) ?></span>
      </h2>
      <svg class="chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <polyline points="6 9 12 15 18 9"/>
      </svg>
    </div>
    <div class="section-box-body">
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
          <button class="btn btn-success btn-sm" onclick="saveIconEdit()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
            ذخیره تغییرات
          </button>
          <button class="btn btn-danger btn-sm" id="iconDeleteBtn" onclick="deleteIcon()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <polyline points="3 6 5 6 21 6"/>
              <path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/>
            </svg>
            حذف آیکون
          </button>
        </div>
      </div>
      <div class="add-asset-form">
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
        <div style="margin-top:10px;">
          <button class="btn btn-primary btn-sm" onclick="addNewIcon()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
            افزودن آیکون
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- ── مدیریت انیمیشن‌های کارت ── -->
  <div class="section-box" id="decosBox">
    <div class="section-box-head" onclick="toggleBox('decosBox')">
      <h2>
        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
          <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>
        </svg>
        مدیریت انیمیشن‌های کارت
        <span class="count-badge" id="decoCountBadge"><?= count($decosData) ?></span>
      </h2>
      <svg class="chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <polyline points="6 9 12 15 18 9"/>
      </svg>
    </div>
    <div class="section-box-body">
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
          <div id="decoEditorPreview" style="width:100%;max-width:280px;height:72px;border-radius:8px;background:rgba(88,166,255,.05);border:1px solid var(--border);overflow:hidden;--card-color:#58a6ff;"></div>
        </div>
        <div class="asset-editor-actions">
          <button class="btn btn-success btn-sm" onclick="saveDecoEdit()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
            ذخیره تغییرات
          </button>
          <button class="btn btn-secondary btn-sm" onclick="refreshDecoPreview()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <polyline points="1 4 1 10 7 10"/>
              <path d="M3.51 15a9 9 0 102.13-9.36L1 10"/>
            </svg>
            پیش‌نمایش
          </button>
          <button class="btn btn-danger btn-sm" id="decoDeleteBtn" onclick="deleteDeco()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <polyline points="3 6 5 6 21 6"/>
              <path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/>
            </svg>
            حذف
          </button>
        </div>
      </div>
      <div class="add-asset-form">
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
        <div style="margin-top:10px;">
          <button class="btn btn-primary btn-sm" onclick="addNewDeco()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
            افزودن انیمیشن
          </button>
        </div>
      </div>
    </div>
  </div>

</div><!-- /admin-wrap -->

<!-- ── مودال ویرایش کاربر ── -->
<div class="modal-overlay" id="userModal" role="dialog" aria-modal="true">
  <div class="modal" style="max-width:480px;">
    <div class="modal-head">
      <h3>ویرایش کاربر</h3>
      <button class="modal-close" onclick="closeModal('userModal')" aria-label="بستن">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
          <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
        </svg>
      </button>
    </div>
    <div class="modal-body" style="display:block;padding:20px;">
      <input type="hidden" id="editUserId">
      <div style="display:flex;flex-direction:column;gap:14px;">
        <div class="field">
          <label>نام و نام خانوادگی <span class="req">*</span></label>
          <input type="text" id="editFullName" maxlength="60">
        </div>
        <div class="field">
          <label>ایمیل <span class="req">*</span></label>
          <input type="email" id="editEmail" maxlength="190" dir="ltr" style="direction:ltr;text-align:left">
        </div>
        <div class="field">
          <label>رمز عبور جدید <span style="color:var(--text-3);font-weight:400;">(خالی = بدون تغییر)</span></label>
          <input type="password" id="editUserPassword" placeholder="حداقل ۶ کاراکتر" autocomplete="new-password">
        </div>
      </div>
    </div>
    <div class="modal-foot">
      <button class="btn btn-secondary btn-sm" onclick="closeModal('userModal')">انصراف</button>
      <button class="btn btn-primary btn-sm" onclick="saveUserEdit()">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
        ذخیره
      </button>
    </div>
  </div>
</div>

<!-- ── مودال دسترسی دو سطحی ── -->
<div class="modal-overlay" id="accessModal" role="dialog" aria-modal="true">
  <div class="modal" style="max-width:580px;">
    <div class="modal-head">
      <h3 id="accessModalTitle">تنظیم دسترسی</h3>
      <button class="modal-close" onclick="closeModal('accessModal')" aria-label="بستن">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
          <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
        </svg>
      </button>
    </div>
    <div class="modal-body" style="display:block;padding:20px;overflow-y:auto;max-height:65vh;">
      <input type="hidden" id="accessUserId">

      <!-- بخش badge ها -->
      <div class="access-section">
        <div class="access-section-title">
          <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M20.59 13.41l-7.17 7.17a2 2 0 01-2.83 0L2 12V2h10l8.59 8.59a2 2 0 010 2.82z"/>
            <line x1="7" y1="7" x2="7.01" y2="7"/>
          </svg>
          دسته‌بندی‌ها
          <span style="font-size:11px;color:var(--text-3);font-weight:400;">(دسترسی گروهی به همه ابزارهای یک دسته)</span>
        </div>
        <div class="access-badges-grid" id="accessBadgesGrid">
          <div style="color:var(--text-3);font-size:13px;">در حال بارگذاری...</div>
        </div>
      </div>

      <div style="height:1px;background:var(--border);margin:18px 0;"></div>

      <!-- بخش ابزارها -->
      <div class="access-section">
        <div class="access-section-title">
          <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2">
            <rect x="2" y="3" width="20" height="14" rx="2"/>
            <line x1="8" y1="21" x2="16" y2="21"/>
            <line x1="12" y1="17" x2="12" y2="21"/>
          </svg>
          ابزارهای خاص
          <span style="font-size:11px;color:var(--text-3);font-weight:400;">(دسترسی مستقیم به ابزار مشخص)</span>
        </div>
        <div class="access-tools-list" id="accessToolsList">
          <div style="color:var(--text-3);font-size:13px;">در حال بارگذاری...</div>
        </div>
      </div>
    </div>
    <div class="modal-foot">
      <button class="btn btn-secondary btn-sm" onclick="closeModal('accessModal')">انصراف</button>
      <button class="btn btn-primary btn-sm" id="saveAccessBtn" onclick="saveAccess()">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
        ذخیره دسترسی‌ها
      </button>
    </div>
  </div>
</div>

<!-- ── مودال تایید حذف ── -->
<div class="modal-overlay" id="confirmModal" role="dialog" aria-modal="true" aria-labelledby="confirmTitle">
  <div class="modal confirm-modal">
    <div class="modal-head">
      <h3 id="confirmTitle">تاییدیه</h3>
      <button class="modal-close" onclick="closeConfirm()" aria-label="بستن">
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
      <button class="btn btn-secondary btn-sm" onclick="closeConfirm()">انصراف</button>
      <button class="btn btn-sm" id="confirmActionBtn" onclick="runConfirm()">تایید</button>
    </div>
  </div>
</div>

<!-- ── Toast ── -->
<div class="toast" id="toast">
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" id="toastIcon"></svg>
  <span id="toastMsg"></span>
</div>

<!-- داده‌های PHP به JS -->
<script>
  const CSRF_TOKEN = '<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>';
  window.CSRF_TOKEN = CSRF_TOKEN; // لازم برای ارسال هدر X-CSRF-Token در admin.js
  // نسخه «سبک» از همه ابزارها (id/title/badge/iconKey/deco/is_public) — برای
  // مرتب‌سازی، مودال دسترسی و شمارش آیکون/دکو. لیست کامل کارت‌ها سمت سرور
  // صفحه‌بندی می‌شود (ToolsView → list_tools). TOOLS_RAW همان آرایه سبک است.
  const tools      = <?= $toolsLite ?>;
  const TOOLS_RAW  = tools;
  window.tools     = tools;
  const TOOLS_TOTAL = <?= (int) ($toolsTotal ?? 0) ?>;
  const ICONS_DATA = <?= $iconsJson ?>;
  const DECOS_DATA = <?= $decosJson ?>;
</script>
<script src="/assets/js/tooltip.js?v=<?= asset_v(__DIR__ . '/../../assets/js/tooltip.js') ?>" defer></script>
<script src="/assets/admin/admin.js?v=<?= asset_v(__DIR__ . '/../../assets/admin/admin.js') ?>"></script>

<footer class="admin-footer">
  <span class="admin-version" dir="ltr"><?= htmlspecialchars(app_version_label()) ?></span>
</footer>

</body>
</html>