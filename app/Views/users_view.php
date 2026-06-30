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
  <script>
    (function(){
      const t = localStorage.getItem('theme');
      const d = window.matchMedia('(prefers-color-scheme: dark)').matches;
      if (t === 'dark' || (!t && d)) document.documentElement.setAttribute('data-theme','dark');
    })();
  </script>
  <link rel="preload" href="/fonts/vazir-font/Vazir-Variable.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="stylesheet" href="/assets/admin/admin.css?v=<?= asset_v(__DIR__ . '/../../assets/admin/admin.css') ?>">
  <style>
    /* ── جستجوی کاربران ── */
    .user-search { position:relative; margin-bottom:16px; }
    .user-search-icon {
      position:absolute; top:50%; right:14px; transform:translateY(-50%);
      width:18px; height:18px; color:var(--text-3); pointer-events:none;
    }
    .user-search input {
      width:100%; box-sizing:border-box;
      font-family:'DashboardFont',sans-serif; font-size:14px;
      background:var(--bg-input); color:var(--text);
      border:1px solid var(--border); border-radius:var(--radius);
      padding:11px 44px 11px 40px; outline:none;
      transition:border-color var(--t), box-shadow var(--t);
    }
    .user-search input:focus { border-color:var(--border-focus); box-shadow:0 0 0 3px rgba(88,166,255,.12); }
    .user-search-clear {
      position:absolute; top:50%; left:10px; transform:translateY(-50%);
      width:26px; height:26px; border-radius:50%; border:none;
      background:var(--bg-card); color:var(--text-3); cursor:pointer;
      display:none; align-items:center; justify-content:center;
      transition:background var(--t), color var(--t);
    }
    .user-search-clear svg { width:13px; height:13px; }
    .user-search-clear:hover { background:var(--danger-bg); color:var(--danger); }
    .user-search.has-value .user-search-clear { display:flex; }

    .user-list { display:flex; flex-direction:column; gap:8px; margin-bottom:22px; }

    /* صفحه‌بندی سمت سرور (لینک‌محور) — هم‌استایل با pager ابزارها */
    a.pg-btn { display:inline-flex; align-items:center; justify-content:center; text-decoration:none; }
    .user-pagination { display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; margin-bottom:22px; }
  </style>
</head>
<body>

<!-- ── هدر یکپارچه (سبک تلگرام) ── -->
<header class="app-header">
  <div class="app-header__inner">
    <div class="app-header__lead"><h1 class="app-header__title">مدیریت کاربران</h1></div>
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

  <!-- ── سرتیتر ── -->
  <div class="tools-header">
    <h2>کاربران <span class="count-badge" id="userCountBadge"><?= (int) ($totalUsers ?? 0) ?></span></h2>
    <div class="tools-header-actions">
      <button class="btn btn-primary btn-sm" onclick="UserManager.openAdd()">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
          <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
        </svg>
        افزودن کاربر
      </button>
      <button class="btn btn-secondary btn-sm" onclick="openBlocksModal()" title="مدیریت انسداد ورود">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>
        </svg>
        انسداد ورود
      </button>
    </div>
  </div>

  <!-- ── جستجو (سمت سرور) ── -->
  <form class="user-search" method="GET" action="/admin" role="search">
    <input type="hidden" name="page" value="users">
    <svg class="user-search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
      <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
    </svg>
    <input type="text" name="q" value="<?= htmlspecialchars($userSearch ?? '', ENT_QUOTES) ?>"
           placeholder="جستجو در نام، شماره موبایل و نام کاربری... (Enter)" autocomplete="off" autofocus>
    <?php if (($userSearch ?? '') !== ''): ?>
      <a class="user-search-clear" href="/admin?page=users" title="پاک کردن" style="display:flex;">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
          <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
        </svg>
      </a>
    <?php endif; ?>
  </form>

  <!-- ── لیست کاربران ── -->
  <div class="user-list" id="userList">
    <?php if (empty($users)): ?>
      <div class="empty-tools">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
          <path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/>
          <circle cx="9" cy="7" r="4"/>
        </svg>
        <p><?= ($userSearch ?? '') !== '' ? 'کاربری با این مشخصات یافت نشد' : 'هنوز هیچ کاربری ثبت نشده' ?></p>
      </div>
    <?php else: foreach ($users as $u):
      $searchKey = trim(($u['display_name'] ?? '') . ' ' . ($u['phone'] ?? '') . ' ' . ($u['username'] ?? ''));
    ?>
      <div class="user-row" data-uid="<?= (int)$u['id'] ?>" data-search="<?= htmlspecialchars($searchKey, ENT_QUOTES) ?>">
        <div class="user-row-avatar"><?= htmlspecialchars(mb_substr($u['display_name'] ?: $u['username'], 0, 1)) ?></div>
        <div class="user-row-info">
          <h3><?= htmlspecialchars($u['display_name'] ?: $u['username']) ?></h3>
          <p style="direction:ltr;text-align:right;"><?= htmlspecialchars($u['phone'] ?: '—') ?></p>
        </div>
        <div class="user-row-meta">
          <?php if (($u['role'] ?? 'user') === 'admin'): ?>
            <span class="user-role-pill admin" title="دسترسی به پنل مدیریت">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4">
                <path d="M12 2l8 4v6c0 5-3.5 8-8 10-4.5-2-8-5-8-10V6l8-4z"/>
              </svg>
              مدیر
            </span>
          <?php endif; ?>
          <span class="user-status-pill <?= $u['is_active'] ? 'active' : 'inactive' ?>">
            <?= $u['is_active'] ? 'فعال' : 'غیرفعال' ?>
          </span>
        </div>
        <div class="user-row-actions">
          <button class="btn btn-secondary btn-icon btn-sm" title="تنظیم دسترسی"
            onclick="openAccessModal(<?= (int)$u['id'] ?>,'<?= htmlspecialchars(addslashes($u['display_name'] ?: $u['username']), ENT_QUOTES) ?>')">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <circle cx="12" cy="12" r="3"/>
              <path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/>
            </svg>
          </button>
          <button class="btn btn-secondary btn-icon btn-sm sess-user-btn" title="نشست‌های فعال"
            onclick="SessionsManager.openUser(<?= (int)$u['id'] ?>,'<?= htmlspecialchars(addslashes($u['display_name'] ?: $u['username']), ENT_QUOTES) ?>')">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/>
            </svg>
            <?php if (!empty($sessionCounts[$u['id']])): ?><span class="sess-count-dot"><?= (int) $sessionCounts[$u['id']] ?></span><?php endif; ?>
          </button>
          <button class="btn btn-secondary btn-icon btn-sm" title="ویرایش"
            onclick="openEditUserModal(<?= (int)$u['id'] ?>,'<?= htmlspecialchars(addslashes($u['display_name'] ?: trim(($u['first_name'] ?? '').' '.($u['last_name'] ?? ''))), ENT_QUOTES) ?>','<?= htmlspecialchars(addslashes($u['username'] ?? ''), ENT_QUOTES) ?>','<?= htmlspecialchars(addslashes($u['phone'] ?? ''), ENT_QUOTES) ?>','<?= htmlspecialchars($u['role'] ?? 'user', ENT_QUOTES) ?>')">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/>
              <path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/>
            </svg>
          </button>
          <button class="btn btn-secondary btn-icon btn-sm toggle-user-btn <?= !$u['is_active'] ? 'is-inactive' : '' ?>"
            title="<?= $u['is_active'] ? 'غیرفعال کردن' : 'فعال کردن' ?>"
            onclick="toggleUser(<?= (int)$u['id'] ?>, this)">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M18.36 6.64A9 9 0 1 1 5.64 17.36"/>
              <line x1="12" y1="2" x2="12" y2="12"/>
            </svg>
          </button>
          <button class="btn btn-danger btn-icon btn-sm" title="حذف"
            onclick="openDeleteUserModal(<?= (int)$u['id'] ?>,'<?= htmlspecialchars(addslashes($u['display_name'] ?: $u['username']), ENT_QUOTES) ?>')">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <polyline points="3 6 5 6 21 6"/>
              <path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/>
              <path d="M10 11v6M14 11v6M9 6V4a1 1 0 011-1h4a1 1 0 011 1v2"/>
            </svg>
          </button>
        </div>
      </div>
    <?php endforeach; endif; ?>
  </div>

  <!-- ── صفحه‌بندی سمت سرور ── -->
  <?php if (($userPages ?? 1) > 1):
    $qStr  = ($userSearch ?? '') !== '' ? '&q=' . rawurlencode($userSearch) : '';
    $from  = ($userPage - 1) * $perPage + 1;
    $to    = $from + count($users) - 1;
    $pgUrl = fn($p) => '/admin?page=users&p=' . (int) $p . $qStr;
  ?>
    <nav class="user-pagination" aria-label="صفحه‌بندی کاربران">
      <span class="pg-info">نمایش <?= $from ?> تا <?= $to ?> از <?= (int) $totalUsers ?> کاربر</span>
      <div class="pg-controls">
        <?php if ($userPage > 1): ?>
          <a class="pg-btn" href="<?= $pgUrl($userPage - 1) ?>" aria-label="قبلی">«</a>
        <?php else: ?>
          <span class="pg-btn" aria-disabled="true" style="opacity:.45;">«</span>
        <?php endif; ?>
        <?php for ($i = 1; $i <= $userPages; $i++):
          $near = ($i === 1 || $i === $userPages || abs($i - $userPage) <= 2);
          if (!$near) { if ($i === 2 || $i === $userPages - 1) echo '<span class="pg-ellipsis">…</span>'; continue; }
          if ($i === $userPage): ?>
            <span class="pg-btn active"><?= $i ?></span>
          <?php else: ?>
            <a class="pg-btn" href="<?= $pgUrl($i) ?>"><?= $i ?></a>
        <?php endif; endfor; ?>
        <?php if ($userPage < $userPages): ?>
          <a class="pg-btn" href="<?= $pgUrl($userPage + 1) ?>" aria-label="بعدی">»</a>
        <?php else: ?>
          <span class="pg-btn" aria-disabled="true" style="opacity:.45;">»</span>
        <?php endif; ?>
      </div>
    </nav>
  <?php endif; ?>


</div><!-- /admin-wrap -->

<!-- ── مودال کاربر (افزودن/ویرایش یکپارچه) ── -->
<div class="modal-overlay" id="userModal" role="dialog" aria-modal="true">
  <div class="modal" style="max-width:480px;">
    <div class="modal-head">
      <h3 id="userModalTitle">افزودن کاربر</h3>
      <button class="modal-close" onclick="UserManager.close()" aria-label="بستن">
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
          <input type="text" id="editFullName" placeholder="مثال: علی محمدی" maxlength="60">
        </div>
        <div class="field">
          <label>نام‌کاربری <span class="req">*</span></label>
          <input type="text" id="editUsername" placeholder="مثال: ali_mohammadi" maxlength="60" dir="ltr" style="direction:ltr;text-align:left">
        </div>
        <div class="field">
          <label>شماره موبایل <span class="req">*</span></label>
          <input type="tel" id="editPhone" placeholder="09123456789" maxlength="11" dir="ltr" style="direction:ltr;text-align:left">
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
            <input type="password" id="editUserPassword" placeholder="حداقل ۶ کاراکتر" autocomplete="new-password"
                   oninput="checkStrength(this.value,'editPassStrength','editPassStrengthLabel')">
            <button type="button" class="pass-toggle" aria-label="نمایش/مخفی رمز" onclick="togglePass('editUserPassword', this)">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            </button>
          </div>
          <div class="pass-strength" id="editPassStrength" style="display:none;">
            <div class="pass-strength-bar"></div><div class="pass-strength-bar"></div><div class="pass-strength-bar"></div><div class="pass-strength-bar"></div>
          </div>
          <div class="pass-strength-label" id="editPassStrengthLabel"></div>
        </div>
      </div>
    </div>
    <div class="modal-foot">
      <button class="btn btn-secondary btn-sm" onclick="UserManager.close()">انصراف</button>
      <button class="btn btn-primary btn-sm" id="userModalSaveBtn" onclick="UserManager.save()">
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
      <button class="modal-close" onclick="closeModal('blocksModal')" aria-label="بستن">
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
      <button class="btn btn-secondary btn-sm" onclick="SecurityManager.refresh()">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M23 4v6h-6M1 20v-6h6"/><path d="M3.51 9a9 9 0 0114.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0020.49 15"/></svg>
        بروزرسانی
      </button>
      <button class="btn btn-secondary btn-sm" onclick="closeModal('blocksModal')">بستن</button>
    </div>
  </div>
</div>

<!-- ── مودال نشست‌های فعال کاربر ── -->
<div class="modal-overlay" id="sessionsUserModal" role="dialog" aria-modal="true">
  <div class="modal" style="max-width:640px;">
    <div class="modal-head">
      <h3 id="sessionsUserTitle">نشست‌های فعال</h3>
      <button class="modal-close" onclick="closeModal('sessionsUserModal')" aria-label="بستن">
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
      <button class="btn btn-danger btn-sm" onclick="SessionsManager.terminateUser()">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18.36 6.64A9 9 0 1 1 5.64 17.36"/><line x1="12" y1="2" x2="12" y2="12"/></svg>
        خروج از همه دستگاه‌ها
      </button>
      <button class="btn btn-secondary btn-sm" onclick="closeModal('sessionsUserModal')">بستن</button>
    </div>
  </div>
</div>

<!-- ── مودال دسترسی دو سطحی ── -->
<div class="modal-overlay" id="accessModal" role="dialog" aria-modal="true">
  <div class="modal" style="max-width:580px;">
    <div class="modal-head">
      <h3 id="accessModalTitle">تنظیم دسترسی</h3>
      <button class="modal-close" onclick="AccessManager.close()" aria-label="بستن">
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
      <button class="btn btn-secondary btn-sm" onclick="AccessManager.close()">انصراف</button>
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
  // مودال دسترسی به «همه ابزارها» نیاز دارد → نسخه سبک (id/title/badge/is_public)
  const TOOLS_RAW  = <?= $toolsLite ?>;
  const tools      = TOOLS_RAW;
  window.tools     = tools;
  // متغیرهای داشبورد ابزارها در این صفحه استفاده نمی‌شوند ولی برای سازگاری تعریف می‌شوند
  const ICONS_DATA = {};
  const DECOS_DATA = {};
</script>
<script src="/assets/js/tooltip.js?v=<?= asset_v(__DIR__ . '/../../assets/js/tooltip.js') ?>" defer></script>
<script src="/assets/admin/admin.js?v=<?= asset_v(__DIR__ . '/../../assets/admin/admin.js') ?>"></script>

<footer class="admin-footer">
  <span class="admin-version" dir="ltr"><?= htmlspecialchars(app_version_label()) ?></span>
</footer>

</body>
</html>