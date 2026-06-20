<?php
// ═══════════════════════════════════════════════════════════
// View: notifications.php — مدیریت اعلان‌ها (صفحه مستقل)
// ═══════════════════════════════════════════════════════════
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>مدیریت اعلان‌ها — پنل مدیریت</title>
  <script>
    (function(){
      const t = localStorage.getItem('theme');
      const d = window.matchMedia('(prefers-color-scheme: dark)').matches;
      if (t === 'dark' || (!t && d)) document.documentElement.setAttribute('data-theme','dark');
    })();
  </script>
  <link rel="preload" href="/fonts/vazir-font/Vazir-Variable.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="stylesheet" href="/admin/assets/admin.css?v=<?= asset_v(__DIR__ . '/../../admin/assets/admin.css') ?>">
  <link rel="stylesheet" href="/assets/css/datepicker.css?v=<?= asset_v(__DIR__ . '/../../assets/css/datepicker.css') ?>">
  <link rel="stylesheet" href="/admin/assets/notifications-admin.css?v=<?= asset_v(__DIR__ . '/../../admin/assets/notifications-admin.css') ?>">
</head>
<body>

<div class="admin-wrap">

  <div class="topbar">
    <div class="topbar-title">مدیریت اعلان‌ها</div>
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

  <div class="tools-header">
    <h2>اعلان‌ها <span class="count-badge" id="notifCountBadge">0</span></h2>
    <button class="btn btn-primary btn-sm" onclick="NM.openAdd()">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
        <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
      </svg>
      اعلان جدید
    </button>
  </div>

  <div class="notif-list-controls">
    <div class="notif-search">
      <svg class="notif-search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
      </svg>
      <input type="text" id="notifSearchInput" placeholder="جستجو در عنوان و متن اعلان‌ها..."
             oninput="NM.onSearchInput(this.value)" autocomplete="off">
      <button type="button" class="notif-search-clear" id="notifSearchClear" onclick="NM.clearSearch()" title="پاک کردن">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
          <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
        </svg>
      </button>
    </div>

    <label class="nm-perpage" title="تعداد آیتم در هر صفحه">
      <span class="sr-only">تعداد در هر صفحه</span>
      <select id="notifPerPage" onchange="NM.setPerPage(this.value)" aria-label="تعداد آیتم در هر صفحه">
        <option value="10">۱۰</option>
        <option value="20">۲۰</option>
        <option value="50">۵۰</option>
      </select>
    </label>

    <button type="button" class="nm-adv-toggle" id="nmAdvToggle" onclick="NM.toggleAdvanced()"
            aria-expanded="false" aria-controls="nmAdvPanel" title="جستجوی پیشرفته">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
        <line x1="4" y1="6" x2="20" y2="6"/><line x1="7" y1="12" x2="17" y2="12"/><line x1="10" y1="18" x2="14" y2="18"/>
      </svg>
      <span>فیلتر</span>
    </button>
  </div>

  <!-- پنل جستجوی پیشرفته -->
  <div class="nm-adv-panel" id="nmAdvPanel">
    <div class="nm-adv-field">
      <label for="nm-df">از تاریخ</label>
      <input type="date" id="nm-df" dir="ltr" class="datetime-ltr">
    </div>
    <div class="nm-adv-field">
      <label for="nm-dt">تا تاریخ</label>
      <input type="date" id="nm-dt" dir="ltr" class="datetime-ltr">
    </div>
    <div class="nm-adv-field">
      <label for="nm-st">وضعیت</label>
      <select id="nm-st">
        <option value="">همه</option>
        <option value="active">فعال</option>
        <option value="expired">منقضی‌شده</option>
      </select>
    </div>
    <div class="nm-adv-actions">
      <button type="button" class="btn btn-primary btn-sm" onclick="NM.applyFilters()">اعمال</button>
      <button type="button" class="btn btn-secondary btn-sm" onclick="NM.resetFilters()">حذف فیلترها</button>
    </div>
  </div>

  <div class="notif-list" id="notifList">
    <div class="notif-skeleton"></div>
    <div class="notif-skeleton"></div>
    <div class="notif-skeleton"></div>
  </div>

  <div class="notif-page-info" id="notifPageInfo"></div>
  <div class="notif-pagination hidden" id="notifPagination"></div>

</div>

<!-- مودال افزودن / ویرایش -->
<div class="modal-overlay" id="notifFormModal" role="dialog" aria-modal="true">
  <div class="modal" style="max-width:600px;">
    <div class="modal-head">
      <h3 id="notifModalTitle">اعلان جدید</h3>
      <button class="modal-close" onclick="NM.closeForm()" aria-label="بستن">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
          <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
        </svg>
      </button>
    </div>

    <div class="notif-modal-body">

      <div class="field">
        <label for="nf-title">عنوان <span class="req">*</span></label>
        <input type="text" id="nf-title" placeholder="عنوان اعلان" maxlength="200">
      </div>

      <div class="field">
        <label for="nf-body">متن</label>

        <!-- نوار ابزار ویرایشگر -->
        <div class="rte-toolbar" id="rteToolbar" role="toolbar" aria-label="ابزار قالب‌بندی متن">
          <button type="button" class="rte-btn" data-cmd="bold" title="پررنگ (Ctrl+B)"><b>B</b></button>
          <button type="button" class="rte-btn" data-cmd="italic" title="مورب (Ctrl+I)"><i>I</i></button>
          <button type="button" class="rte-btn" data-cmd="underline" title="زیرخط (Ctrl+U)"><u>U</u></button>
          <span class="rte-sep"></span>
          <button type="button" class="rte-btn" data-cmd="justifyRight" title="راست‌چین">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="21" y1="6" x2="3" y2="6"/><line x1="21" y1="12" x2="9" y2="12"/><line x1="21" y1="18" x2="7" y2="18"/></svg>
          </button>
          <button type="button" class="rte-btn" data-cmd="justifyCenter" title="وسط‌چین">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="21" y1="6" x2="3" y2="6"/><line x1="18" y1="12" x2="6" y2="12"/><line x1="20" y1="18" x2="4" y2="18"/></svg>
          </button>
          <button type="button" class="rte-btn" data-cmd="justifyLeft" title="چپ‌چین">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="21" y1="6" x2="3" y2="6"/><line x1="15" y1="12" x2="3" y2="12"/><line x1="17" y1="18" x2="3" y2="18"/></svg>
          </button>
          <button type="button" class="rte-btn" data-cmd="justifyFull" title="هم‌تراز">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="21" y1="6" x2="3" y2="6"/><line x1="21" y1="12" x2="3" y2="12"/><line x1="21" y1="18" x2="3" y2="18"/></svg>
          </button>
          <span class="rte-sep"></span>
          <button type="button" class="rte-btn" data-dir="rtl" title="جهت راست‌به‌چپ">RTL</button>
          <button type="button" class="rte-btn" data-dir="ltr" title="جهت چپ‌به‌راست">LTR</button>
          <span class="rte-sep"></span>
          <button type="button" class="rte-btn" data-cmd="insertUnorderedList" title="لیست نقطه‌ای">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><circle cx="3.5" cy="6" r="1.2" fill="currentColor"/><circle cx="3.5" cy="12" r="1.2" fill="currentColor"/><circle cx="3.5" cy="18" r="1.2" fill="currentColor"/></svg>
          </button>
          <button type="button" class="rte-btn" data-cmd="insertOrderedList" title="لیست شماره‌دار">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="10" y1="6" x2="21" y2="6"/><line x1="10" y1="12" x2="21" y2="12"/><line x1="10" y1="18" x2="21" y2="18"/><text x="2" y="8" font-size="7" fill="currentColor" stroke="none">1</text><text x="2" y="14" font-size="7" fill="currentColor" stroke="none">2</text><text x="2" y="20" font-size="7" fill="currentColor" stroke="none">3</text></svg>
          </button>
          <span class="rte-sep"></span>
          <label class="rte-color" title="رنگ متن">
            <span class="rte-color-swatch" id="rteColorSwatch"></span>
            <input type="color" id="rteColor" value="#e11d48">
            <span class="rte-color-label">رنگ</span>
          </label>
          <button type="button" class="rte-btn" data-cmd="removeFormat" title="پاک کردن همه قالب‌ها">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 7V4h16v3"/><path d="M5 20h6"/><path d="M13 4L8 20"/><line x1="18" y1="14" x2="22" y2="18"/><line x1="22" y1="14" x2="18" y2="18"/></svg>
          </button>
        </div>

        <div id="nf-body" class="notif-body rte-editor" contenteditable="true"
             data-placeholder="متن اعلان (اختیاری) — می‌توانید پررنگ، رنگی، چپ/راست‌چین و … کنید"
             dir="rtl"></div>

        <div class="rte-counter" id="rteCounter">
          <span class="rte-counter-nums" dir="ltr"><span id="rteCount">0</span> / 20,000</span> کاراکتر
        </div>
      </div>

      <div class="field">
        <label>تصویر <span style="color:var(--text-3);font-weight:400;">(اختیاری — حداکثر ۵۰ مگابایت)</span></label>

        <!-- ناحیه کشیدن‌ورها‌کردن / انتخاب فایل (تک‌فایل) -->
        <div class="file-up-zone" id="imgUploadZone">
          <input type="file" id="imgFileInput" accept="image/*" onchange="NM.handleFileSelect(this.files[0])">
          <div class="file-up-illus">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
              <path d="M3 7a2 2 0 0 1 2-2h4l2 2h6a2 2 0 0 1 2 2v3"/>
              <path d="M3 9v8a2 2 0 0 0 2 2h8"/>
              <path d="M17 21v-6"/><path d="M14.5 17.5 17 15l2.5 2.5"/>
            </svg>
          </div>
          <p class="file-up-title">فایل را اینجا بکشید و رها کنید یا <span class="file-up-link">انتخاب کنید</span></p>
          <span class="file-up-hint">حداکثر حجم فایل ۵۰ مگابایت</span>
        </div>

        <!-- ردیف فایل انتخاب‌شده -->
        <div class="file-item" id="imgFileItem" hidden>
          <div class="file-item-thumb" id="imgFileThumb">
            <img id="imgPreview" src="" alt="">
            <svg class="file-item-thumb-ph" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
              <rect x="3" y="3" width="18" height="18" rx="3"/>
              <circle cx="8.5" cy="8.5" r="1.5" fill="currentColor" stroke="none"/>
              <polyline points="21 15 16 10 5 21"/>
            </svg>
          </div>
          <div class="file-item-main">
            <div class="file-item-name" id="imgFileName"></div>
            <div class="file-item-sub" id="imgFileSize"></div>
            <div class="file-item-bar"><span class="file-item-bar-fill" id="imgFileBar"></span></div>
          </div>
          <div class="file-item-pct" id="imgFilePct">0%</div>
          <span class="file-item-spin" id="imgFileSpin"></span>
          <svg class="file-item-done" id="imgFileDone" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="20 6 9 17 4 12"/>
          </svg>
          <button type="button" class="file-item-x" onclick="NM.removeImage()" title="حذف">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
              <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
            </svg>
          </button>
        </div>
      </div>

      <div class="field">
        <label>مخاطبان</label>
        <div class="audience-box">
          <div class="audience-row">
            <div class="audience-row-label">
              <strong>نمایش عمومی</strong>
              <span>برای همه بازدیدکنندگان از جمله مهمان‌ها</span>
            </div>
            <label class="toggle-sw">
              <input type="checkbox" id="nf-public" onchange="NM.onPublicChange(this)">
              <span class="toggle-sw-track"></span>
            </label>
          </div>
          <div class="audience-row" id="targetAllRow">
            <div class="audience-row-label">
              <strong>همه کاربران</strong>
              <span>برای تمام کاربران لاگین‌کرده</span>
            </div>
            <label class="toggle-sw">
              <input type="checkbox" id="nf-all-users">
              <span class="toggle-sw-track"></span>
            </label>
          </div>
          <div class="audience-row" style="flex-direction:column;align-items:flex-start;gap:8px;" id="badgesRow">
            <div class="audience-row-label">
              <strong>دسته‌بندی‌های خاص</strong>
              <span>فقط کاربرانی که به این دسته‌ها دسترسی دارند</span>
            </div>
            <div class="badge-check-grid" id="badgeCheckGrid"></div>
          </div>
        </div>
      </div>

      <!-- تاریخ و ساعت انقضا — دو input جداگانه برای سازگاری با همه مرورگرها -->
      <div class="field">
        <label>
          تاریخ و ساعت انقضا
          <span style="color:var(--text-3);font-weight:400;">(خالی = بدون انقضا)</span>
        </label>
        <div style="display:flex;gap:8px;">
          <input
            type="date"
            id="nf-expires-date"
            class="datetime-ltr"
            style="flex:1;"
            oninput="NM.onExpiryInput()">
          <input
            type="time"
            id="nf-expires-time"
            class="datetime-ltr"
            style="width:130px;"
            value="00:00"
            oninput="NM.onExpiryInput()">
        </div>
        <div class="expiry-display" id="expiryDisplay">
          <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
          </svg>
          <span id="expiryDisplayText"></span>
        </div>
        <span style="font-size:11px;color:var(--text-3);margin-top:4px;display:block;line-height:1.7;">
          پس از این زمان، اعلان برای کسانی که آن را خوانده‌اند از فید فعال حذف می‌شود؛
          اما اگر کاربری هنوز آن را ندیده باشد تا زمان خواندن در فیدش باقی می‌ماند.
          در هر حال اعلان در تاریخچه قابل جستجو است.
        </span>
      </div>

    </div>

    <div class="modal-foot">
      <button class="btn btn-secondary btn-sm" onclick="NM.closeForm()">انصراف</button>
      <button class="btn btn-primary btn-sm" id="notifSaveBtn" onclick="NM.save()">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
        ذخیره
      </button>
    </div>
  </div>
</div>

<!-- مودال تایید (عمومی: حذف / بستن فرم) -->
<div class="modal-overlay" id="notifConfirmModal" role="dialog" aria-modal="true" aria-labelledby="notifConfirmTitle">
  <div class="modal confirm-modal">
    <div class="modal-head">
      <h3 id="notifConfirmTitle">حذف اعلان</h3>
      <button class="modal-close" onclick="NM.closeConfirm()" aria-label="بستن">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
          <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
        </svg>
      </button>
    </div>
    <div class="modal-body">
      <div class="confirm-icon danger" id="notifConfirmIcon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
          <polyline points="3 6 5 6 21 6"/>
          <path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/>
          <path d="M10 11v6M14 11v6M9 6V4a1 1 0 011-1h4a1 1 0 011 1v2"/>
        </svg>
      </div>
      <h4 id="notifConfirmHeading">آیا از حذف این اعلان اطمینان دارید؟</h4>
      <p class="confirm-desc" id="notifConfirmDesc"></p>
    </div>
    <div class="modal-foot">
      <button class="btn btn-secondary btn-sm" onclick="NM.closeConfirm()">انصراف</button>
      <button class="btn btn-danger btn-sm" id="notifConfirmBtn" onclick="NM._runAsk()">حذف اعلان</button>
    </div>
  </div>
</div>

<!-- Toast -->
<div class="toast" id="toast">
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" id="toastIcon"></svg>
  <span id="toastMsg"></span>
</div>

<script>
const CSRF_TOKEN   = '<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>';
const AVAIL_BADGES = <?= $badgesJson ?>;
</script>
<script src="/admin/assets/notifications-admin.js?v=<?= asset_v(__DIR__ . '/../../admin/assets/notifications-admin.js') ?>"></script>
<script src="/assets/js/datepicker.js?v=<?= asset_v(__DIR__ . '/../../assets/js/datepicker.js') ?>"></script>

<footer class="admin-footer">
  <span class="admin-version" dir="ltr"><?= htmlspecialchars(app_version_label()) ?></span>
</footer>

</body>
</html>
