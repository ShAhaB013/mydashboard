<?php
// ═══════════════════════════════════════════════════════════
// View: users_view.php — صفحه مستقل مدیریت کاربران (با جستجو)
// ═══════════════════════════════════════════════════════════
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>مدیریت کاربران — پنل مدیریت</title>
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
    /* ── نوار جستجو/فیلتر/تعداد در هر صفحه (هماهنگ با بخش مدیریت اعلان‌ها) ── */
    .user-list-controls { display:flex; gap:10px; align-items:stretch; margin-bottom:16px; }
    .user-search { position:relative; flex:1; margin-bottom:0; }
    .user-search-icon {
      position:absolute; top:50%; right:14px; transform:translateY(-50%);
      width:18px; height:18px; color:var(--text-3); pointer-events:none;
    }
    .user-search input {
      width:100%; box-sizing:border-box;
      font-family:'DashboardFont',sans-serif; font-size:14px;
      background:var(--bg-card); color:var(--text);
      border:1px solid var(--border); border-radius:var(--radius-xs);
      padding:11px 44px 11px 40px; outline:none;
      transition:border-color var(--t), box-shadow var(--t);
    }
    .user-search input:focus { border-color:var(--border-focus); box-shadow:0 0 0 3px rgba(88,166,255,.12); }
    .user-search-clear {
      position:absolute; top:50%; left:10px; transform:translateY(-50%);
      width:26px; height:26px; border-radius:50%; border:none;
      background:var(--bg-card); color:var(--text-3); cursor:pointer;
      display:none; align-items:center; justify-content:center;
      overflow:hidden;
      transition:background var(--t), color var(--t);
    }
    .user-search-clear svg { width:13px; height:13px; }
    .user-search-clear:hover { background:var(--danger-bg); color:var(--danger); }
    .user-search.has-value .user-search-clear { display:flex; }

    .user-perpage { display:inline-flex; align-items:stretch; flex-shrink:0; }
    .user-perpage select {
      appearance:none; -webkit-appearance:none; -moz-appearance:none;
      font-family:inherit; font-size:13px; font-weight:600; height:100%;
      color:var(--text-2); background:var(--bg-card);
      border:1px solid var(--border); border-radius:var(--radius-xs);
      padding:0 12px 0 30px; cursor:pointer; min-height:42px;
      direction:rtl; text-align:right;
      background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%2364748b' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
      background-repeat:no-repeat; background-position:left 10px center;
    }
    .user-perpage select:hover { border-color:var(--accent); }
    .user-perpage select:focus { outline:none; border-color:var(--border-focus,var(--accent)); }
    .user-perpage .cselect { width:auto; }
    .user-perpage .cselect-trigger { min-height:42px; font-weight:600; border-radius:var(--radius-xs); background:var(--bg-card); }

    /* ── دکمه و پنل جستجوی پیشرفته ── */
    .user-adv-toggle {
      display:inline-flex; align-items:center; gap:6px; flex-shrink:0;
      font-family:'DashboardFont',sans-serif; font-size:13px; font-weight:600;
      padding:0 16px; min-height:42px; cursor:pointer; white-space:nowrap;
      color:var(--text-2); background:var(--bg-card);
      border:1px solid var(--border); border-radius:var(--radius-xs);
      transition:border-color var(--t), color var(--t), background var(--t);
    }
    .user-adv-toggle svg { width:16px; height:16px; flex-shrink:0; }
    .user-adv-toggle:hover { border-color:var(--accent); color:var(--accent); }
    .user-adv-toggle.active,
    .user-adv-toggle.has-filters { border-color:var(--accent); color:var(--accent); background:var(--accent-bg); }
    .user-adv-panel {
      display:none; flex-wrap:wrap; gap:14px; align-items:flex-end;
      margin-bottom:16px; padding:16px 18px;
      background:var(--bg-card); border:1px solid var(--border);
      border-radius:var(--radius-lg);
    }
    .user-adv-panel.open { display:flex; }
    .user-adv-field { display:flex; flex-direction:column; gap:6px; flex:1; min-width:140px; }
    .user-adv-field label { font-size:12px; font-weight:600; color:var(--text-2); }
    .user-adv-field select {
      appearance:none; -webkit-appearance:none; -moz-appearance:none; cursor:pointer;
      font-family:'DashboardFont',sans-serif; font-size:14px; height:42px;
      color:var(--text); background:var(--bg-input);
      border:1px solid var(--border); border-radius:var(--radius-xs);
      padding:0 30px 0 12px;
      background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%2394a3b8' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
      background-repeat:no-repeat; background-position:left 10px center;
      outline:none; transition:border-color var(--t), box-shadow var(--t);
    }
    .user-adv-field select:focus { border-color:var(--border-focus); box-shadow:0 0 0 3px rgba(88,166,255,.12); }
    .user-adv-field .cselect-trigger { min-height:42px; border-radius:var(--radius-xs); }
    .user-adv-actions { display:flex; gap:8px; align-items:center; }
    .user-adv-actions .btn { height:42px; border-radius:var(--radius-xs); }
    @media (max-width:560px) {
      .user-list-controls { flex-wrap:wrap; }
      .user-adv-field { min-width:120px; }
      .user-adv-actions { width:100%; justify-content:flex-end; }
    }

    .user-list { display:flex; flex-direction:column; gap:8px; margin-bottom:10px; }
    .user-skeleton { background:var(--bg-card); border:1px solid var(--border); border-radius:var(--radius-lg); height:76px; animation:userShimmer 1.6s ease-in-out infinite; }
    @keyframes userShimmer { 0%,100%{opacity:.6} 50%{opacity:1} }
    .user-empty { text-align:center; padding:60px 24px; color:var(--text-2); border:1.5px dashed var(--border); border-radius:var(--radius-lg); }
    .user-empty svg { width:40px; height:40px; opacity:.35; display:block; margin:0 auto 12px; }

    /* ── صفحه‌بندی سمت سرور (AJAX) — هم‌ساختار با بخش اعلان‌ها ── */
    .user-page-info { text-align:center; font-size:12px; color:var(--text-3); margin-bottom:8px; }
    .user-pagination {
      display:flex; align-items:center; justify-content:center;
      flex-wrap:wrap; gap:6px; margin:20px 0 8px;
    }
    .user-pagination.hidden { display:none; }
  </style>
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
</head>
<body>

<!-- ── هدر یکپارچه (سبک تلگرام) ── -->
<header class="app-header">
  <div class="app-header__inner">
    <div class="app-header__lead"><h1 class="app-header__title">مدیریت کاربران</h1></div>
    <div class="app-header__actions">
      <button type="button" class="hdr-btn" data-act="userAdd" title="افزودن کاربر" aria-label="افزودن کاربر">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
          <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
        </svg>
      </button>
      <button type="button" class="hdr-btn" data-act="openBlocks" title="مدیریت انسداد ورود" aria-label="مدیریت انسداد ورود">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>
        </svg>
      </button>
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

  <!-- ── سرتیتر ── -->
  <div class="tools-header">
    <h2>کاربران <span class="count-badge" id="userCountBadge">0</span></h2>
  </div>

  <!-- ── تنظیم مدت اعتبار نشست ── -->
  <div class="sess-ttl-row">
    <label for="sessTtlInput">مدت فعال‌بودن نشست هر ورود:</label>
    <input type="text" id="sessTtlInput" value="<?= (int) ($sessionTtlHours ?? 24) ?>" inputmode="numeric" maxlength="3" dir="ltr">
    <span>ساعت</span>
    <button class="btn btn-primary btn-sm" data-act="saveTtl">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
      ذخیره
    </button>
    <span class="sess-ttl-hint">۱ تا ۷۲۰ ساعت — هر کاربر تا این مدت پس از آخرین فعالیت وارد می‌ماند.</span>
  </div>

  <!-- ── جستجو + تعداد در هر صفحه + فیلتر پیشرفته (سمت سرور، AJAX) ── -->
  <div class="user-list-controls">
    <div class="user-search">
      <svg class="user-search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
      </svg>
      <input type="text" id="userSearchInput" placeholder="جستجو در نام، شماره موبایل و نام کاربری..."
             data-input="userSearch" autocomplete="off">
      <button type="button" class="user-search-clear" id="userSearchClear" data-act="userClearSearch" title="پاک کردن">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
          <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
        </svg>
      </button>
    </div>

    <label class="user-perpage" title="تعداد آیتم در هر صفحه">
      <span class="sr-only">تعداد در هر صفحه</span>
      <select id="userPerPage" data-change="userSetPerPage" aria-label="تعداد آیتم در هر صفحه">
        <option value="10">۱۰</option>
        <option value="20">۲۰</option>
        <option value="50">۵۰</option>
      </select>
    </label>

    <button type="button" class="user-adv-toggle" id="userAdvToggle" data-act="userToggleAdvanced"
            aria-expanded="false" aria-controls="userAdvPanel" title="جستجوی پیشرفته">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
        <line x1="4" y1="6" x2="20" y2="6"/><line x1="7" y1="12" x2="17" y2="12"/><line x1="10" y1="18" x2="14" y2="18"/>
      </svg>
      <span>فیلتر</span>
    </button>
  </div>

  <!-- پنل جستجوی پیشرفته -->
  <div class="user-adv-panel" id="userAdvPanel">
    <div class="user-adv-field">
      <label for="user-f-role">نقش</label>
      <select id="user-f-role">
        <option value="">همه</option>
        <option value="admin">مدیر</option>
        <option value="user">کاربر عادی</option>
      </select>
    </div>
    <div class="user-adv-field">
      <label for="user-f-status">وضعیت</label>
      <select id="user-f-status">
        <option value="">همه</option>
        <option value="active">فعال</option>
        <option value="inactive">غیرفعال</option>
      </select>
    </div>
    <div class="user-adv-actions">
      <button type="button" class="btn btn-primary btn-sm" data-act="userApplyFilters">اعمال</button>
      <button type="button" class="btn btn-secondary btn-sm" data-act="userResetFilters">حذف فیلترها</button>
    </div>
  </div>

  <!-- ── لیست کاربران (بارگذاری AJAX) ── -->
  <div class="user-list" id="userList">
    <div class="user-skeleton"></div>
    <div class="user-skeleton"></div>
    <div class="user-skeleton"></div>
  </div>

  <div class="user-page-info" id="userPageInfo"></div>
  <div class="user-pagination hidden" id="userPagination"></div>


</div><!-- /admin-wrap -->

<!-- ── مودال کاربر (افزودن/ویرایش یکپارچه) ── -->
<div class="modal-overlay" id="userModal" role="dialog" aria-modal="true">
  <div class="modal" style="max-width:480px;">
    <div class="modal-head">
      <h3 id="userModalTitle">افزودن کاربر</h3>
      <button class="modal-close" data-act="userClose" aria-label="بستن">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
          <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
        </svg>
      </button>
    </div>
    <div class="modal-body" style="display:block;padding:20px;overflow-y:auto;">
      <input type="hidden" id="editUserId">
      <div style="display:flex;flex-direction:column;gap:14px;">
        <div class="field">
          <label>نام و نام خانوادگی <span class="req">*</span></label>
          <input type="text" id="editFullName" placeholder="مثال: علی محمدی" maxlength="60">
        </div>
        <div class="field">
          <label>نام‌کاربری <span class="req">*</span></label>
          <input type="text" id="editUsername" placeholder="مثال: ali_mohammadi" maxlength="60" dir="ltr" style="direction:ltr;text-align:left">
        </div>
        <div class="field">
          <label>شماره موبایل <span class="opt">(اختیاری)</span></label>
          <input type="tel" id="editPhone" placeholder="09123456789" maxlength="11" dir="ltr" style="direction:ltr;text-align:left">
        </div>
        <div class="field">
          <label>ایمیل <span class="req">*</span></label>
          <input type="email" id="editEmail" placeholder="example@mail.com" maxlength="190" dir="ltr" style="direction:ltr;text-align:left">
        </div>
        <div class="field">
          <label>سطح دسترسی</label>
          <select id="editUserRole">
            <option value="user">کاربر عادی</option>
            <option value="admin">مدیر (دسترسی به پنل)</option>
          </select>
        </div>
        <div class="field">
          <label id="editPassLabel">رمز عبور <span class="req">*</span></label>
          <div class="pass-wrap">
            <input type="password" id="editUserPassword" placeholder="رمز عبور" autocomplete="new-password" maxlength="64">
            <button type="button" class="pass-toggle" aria-label="نمایش/مخفی رمز" data-act="togglePass" data-target="editUserPassword">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            </button>
          </div>
          <!-- چک‌لیست زنده‌ی قوانین رمز عبور (هنگام focus/تایپ به‌روز می‌شود) -->
          <div class="pass-rules" id="editPassRules" aria-live="polite" hidden>
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
      </div>
    </div>
    <div class="modal-foot">
      <button class="btn btn-secondary btn-sm" data-act="userClose">انصراف</button>
      <button class="btn btn-primary btn-sm" id="userModalSaveBtn" data-act="userSave">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
        <span id="userModalSaveLabel">افزودن کاربر</span>
      </button>
    </div>
  </div>
</div>

<!-- ── مودال انسداد ورود (Rate limit) ── -->
<div class="modal-overlay" id="blocksModal" role="dialog" aria-modal="true">
  <div class="modal" style="max-width:640px;">
    <div class="modal-head">
      <h3>انسداد ورود</h3>
      <button class="modal-close" data-act="closeModal" data-modal="blocksModal" aria-label="بستن">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
          <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
        </svg>
      </button>
    </div>
    <div class="modal-body" style="display:block;padding:18px 20px;max-height:70vh;overflow-y:auto;">
      <p class="blocks-hint">
        انسداد بر اساس «آدرس IP» انجام می‌شود. پس از <?= 10 ?> تلاش ناموفق در ۱۵ دقیقه، آن IP به‌مدت ۱۵ دقیقه بلاک می‌شود.
        می‌توانید لاگ تلاش‌ها را ببینید و انسداد را دستی رفع کنید.
      </p>
      <div id="blocksList" class="blocks-list">
        <div class="blocks-loading">در حال بارگذاری…</div>
      </div>
    </div>
    <div class="modal-foot">
      <button class="btn btn-secondary btn-sm" data-act="securityRefresh">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M23 4v6h-6M1 20v-6h6"/><path d="M3.51 9a9 9 0 0114.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0020.49 15"/></svg>
        بروزرسانی
      </button>
      <button class="btn btn-secondary btn-sm" data-act="closeModal" data-modal="blocksModal">بستن</button>
    </div>
  </div>
</div>

<!-- ── مودال نشست‌های فعال کاربر ── -->
<div class="modal-overlay" id="sessionsUserModal" role="dialog" aria-modal="true">
  <div class="modal" style="max-width:640px;">
    <div class="modal-head">
      <h3 id="sessionsUserTitle">نشست‌های فعال</h3>
      <button class="modal-close" data-act="closeModal" data-modal="sessionsUserModal" aria-label="بستن">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
          <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
        </svg>
      </button>
    </div>
    <div class="modal-body" style="display:block;padding:18px 20px;max-height:70vh;overflow-y:auto;">
      <p class="blocks-hint">نشست‌های فعال این کاربر روی دستگاه‌های مختلف. می‌توانید هرکدام را جداگانه، یا همه را با هم پایان دهید (خروج اجباری).</p>
      <div id="sessionsUserList" class="sess-list">
        <div class="blocks-loading">در حال بارگذاری…</div>
      </div>
    </div>
    <div class="modal-foot">
      <button class="btn btn-danger btn-sm" id="sessTerminateUserBtn" data-act="sessTerminateUser" disabled>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18.36 6.64A9 9 0 1 1 5.64 17.36"/><line x1="12" y1="2" x2="12" y2="12"/></svg>
        خروج از همه دستگاه‌ها
      </button>
      <button class="btn btn-secondary btn-sm" data-act="closeModal" data-modal="sessionsUserModal">بستن</button>
    </div>
  </div>
</div>

<!-- ── مودال دسترسی دو سطحی ── -->
<div class="modal-overlay" id="accessModal" role="dialog" aria-modal="true">
  <div class="modal" style="max-width:580px;">
    <div class="modal-head">
      <h3 id="accessModalTitle">تنظیم دسترسی</h3>
      <button class="modal-close" data-act="accessClose" aria-label="بستن">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
          <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
        </svg>
      </button>
    </div>
    <div class="modal-body" style="display:block;padding:20px;overflow-y:auto;max-height:65vh;">
      <input type="hidden" id="accessUserId">

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
      <button class="btn btn-secondary btn-sm" data-act="accessClose">انصراف</button>
      <button class="btn btn-primary btn-sm" id="saveAccessBtn" data-act="saveAccess">
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

<!-- ── Toast ── -->
<div class="toast" id="toast">
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" id="toastIcon"></svg>
  <span id="toastMsg"></span>
</div>

<!-- داده‌های PHP به JS -->
<script nonce="<?= csp_nonce() ?>">
  const CSRF_TOKEN = '<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>';
  window.CSRF_TOKEN = CSRF_TOKEN; // لازم برای ارسال هدر X-CSRF-Token در admin.js
  // مودال دسترسی به «همه ابزارها» نیاز دارد → نسخه سبک (id/title/badge/is_public)
  const TOOLS_RAW  = <?= $toolsLite ?>;
  const tools      = TOOLS_RAW;
  window.tools     = tools;
  // متغیرهای داشبورد ابزارها در این صفحه استفاده نمی‌شوند ولی برای سازگاری تعریف می‌شوند
  const ICONS_DATA = {};
  const DECOS_DATA = {};
</script>
<script src="/assets/js/tooltip.js?v=<?= asset_v(__DIR__ . '/../../assets/js/tooltip.js') ?>" defer></script>
<script src="/assets/js/actions.js?v=<?= asset_v(__DIR__ . '/../../assets/js/actions.js') ?>"></script>
<script src="/assets/admin/admin.js?v=<?= asset_v(__DIR__ . '/../../assets/admin/admin.js') ?>"></script>

</body>
</html>