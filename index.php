<?php
require_once __DIR__ . '/version.php';
// Shared bootstrap: autoload + config + DB + session (DB-backed session needs a DB connection)
$config = require __DIR__ . '/bootstrap.php';

if (!UserSession::check()) {
    header('Location: /login');
    exit;
}

$displayName = UserSession::displayName();
$username    = (string) ($_SESSION['username'] ?? '');
$email       = (string) ($_SESSION['email'] ?? '');
$isAdmin     = UserSession::isAdmin();
$menuName    = $displayName !== '' ? $displayName : $username;

// CSRF token — needed for state-changing requests to api.php (logout / mark_read /
// mark_all_read) as well as inline admin management.
$csrfToken = UserSession::ensureCsrfToken();

$v_css   = asset_v(__DIR__ . '/assets/css/style.css');
$v_js    = asset_v(__DIR__ . '/assets/js/script.js');
$v_lb    = asset_v(__DIR__ . '/assets/js/lightbox.js');
$v_theme = asset_v(__DIR__ . '/assets/js/theme.js');
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <meta name="description" content="داشبورد مجموعه ابزارهای کمکی">
  <meta name="theme-color" content="#3e7de7">
  <meta name="color-scheme" content="light dark">
  <title>داشبورد ابزارهای کمکی</title>
  <link rel="preload" href="fonts/vazir-font/Vazir-Variable.woff2" as="font" type="font/woff2" crossorigin="anonymous">
  <link rel="stylesheet" href="/assets/css/style.css?v=<?= $v_css ?>">
  <script nonce="<?= csp_nonce() ?>">
    (function () {
      const saved = localStorage.getItem('theme');
      const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
      if (saved === 'dark' || (!saved && prefersDark)) {
        document.documentElement.setAttribute('data-theme', 'dark');
      }
    })();
  </script>
  <script src="/assets/js/theme.js?v=<?= $v_theme ?>" defer></script>
  <script src="/assets/js/tooltip.js?v=<?= asset_v(__DIR__ . '/assets/js/tooltip.js') ?>" defer></script>
  <script nonce="<?= csp_nonce() ?>">window.CSRF_TOKEN = <?= json_encode($csrfToken, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>;</script>
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

  <a href="#main-content" class="sr-only">رفتن به محتوا</a>

  <!-- Modal-style dim layer shown while a header popover (bell / user menu) is open.
       Sits below the sticky header, so the header and the popover stay crisp. -->
  <div class="pop-backdrop" id="popBackdrop" aria-hidden="true"></div>

  <header class="app-header">
    <div class="app-header__inner">
      <h1 class="app-header__title">داشبورد مجموعه ابزارهای کمکی</h1>
      <div class="header-actions">

        <!-- Search button (always an icon) — clicking opens the full-width search bar -->
        <button type="button" class="hdr-btn" id="searchToggle" title="جستجو" aria-label="جستجوی ابزار" aria-controls="toolsGrid">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
            <circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>
          </svg>
        </button>

        <button
          class="theme-toggle"
          id="themeToggle"
          aria-label="تغییر به حالت تاریک"
          title="تغییر تم">
          <span class="theme-toggle-icon" aria-hidden="true">
            <svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
              <circle cx="12" cy="12" r="5"/>
              <line x1="12" y1="1"  x2="12" y2="3"/>
              <line x1="12" y1="21" x2="12" y2="23"/>
              <line x1="4.22" y1="4.22"  x2="5.64" y2="5.64"/>
              <line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/>
              <line x1="1" y1="12" x2="3"  y2="12"/>
              <line x1="21" y1="12" x2="23" y2="12"/>
              <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/>
              <line x1="18.36" y1="5.64"  x2="19.78" y2="4.22"/>
            </svg>
            <svg class="icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
              <path d="M21 12.79A9 9 0 1111.21 3a7 7 0 009.79 9.79z"/>
            </svg>
          </span>
        </button>

        <!-- ── Notification bell ── -->
        <div id="notifBellWrap">

          <button
            class="notif-bell-btn"
            id="notifBellBtn"
            aria-label="اعلان‌ها"
            aria-haspopup="true"
            aria-expanded="false"
            aria-controls="notifDropdown"
            title="اعلان‌ها">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
              <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
              <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
            </svg>
            <span class="notif-bell-badge" id="notifBellBadge" aria-live="polite" aria-atomic="true"></span>
          </button>

          <!-- .pop-shell carries the popover's placement + unified drop-shadow;
               the panel inside gets its arrow-included shape from popover.js -->
          <div class="pop-shell">
          <div
            class="notif-dropdown"
            id="notifDropdown"
            role="region"
            aria-label="پنل اعلان‌ها"
            aria-hidden="true">

            <div class="notif-drop-head">
              <span class="notif-drop-head-title">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                  <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
                  <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
                </svg>
                اعلان‌ها
              </span>
            </div>

            <!-- Items are built by JS; infinite-scrolled as the user scrolls near the bottom -->
            <div class="notif-drop-body" id="notifDropdownBody" role="list" aria-busy="true">
              <div class="sk-list-item" aria-hidden="true">
                <div class="sk sk-list-icon"></div>
                <div class="sk-list-lines"><div class="sk sk-line"></div><div class="sk sk-line sk-line--short"></div></div>
              </div>
              <div class="sk-list-item" aria-hidden="true">
                <div class="sk sk-list-icon"></div>
                <div class="sk-list-lines"><div class="sk sk-line"></div><div class="sk sk-line sk-line--short"></div></div>
              </div>
              <div class="sk-list-item" aria-hidden="true">
                <div class="sk sk-list-icon"></div>
                <div class="sk-list-lines"><div class="sk sk-line"></div><div class="sk sk-line sk-line--short"></div></div>
              </div>
            </div>
            <!-- Screen-reader-only announcer for content that streams in silently on scroll -->
            <span id="notifLiveRegion" class="sr-only" aria-live="polite" aria-atomic="true"></span>

            <div class="notif-drop-footer">

              <a href="/notifications" class="notif-drop-view-all">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                  <path d="M9 18l6-6-6-6"/>
                </svg>
                مشاهده همه
              </a>

            </div>

          </div>
          </div><!-- /.pop-shell -->
        </div>
        <!-- /Notification bell -->

        <!-- Auth area — every visitor is authenticated (login enforced server-side above) -->
        <div class="auth-area">

          <div class="user-menu-wrap" id="userMenuWrap">

            <button
              class="user-menu-btn"
              id="userMenuBtn"
              aria-haspopup="true"
              aria-expanded="false"
              aria-controls="userMenuDropdown">
              <span class="user-menu-avatar" id="userMenuAvatar">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
              </span>
              <span class="user-menu-name"   id="userMenuName"><?= htmlspecialchars($menuName, ENT_QUOTES) ?></span>
              <svg class="user-menu-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                <polyline points="6 9 12 15 18 9"/>
              </svg>
            </button>

            <!-- .pop-shell carries the popover's placement + unified drop-shadow -->
            <div class="pop-shell">
            <div class="user-menu-dropdown" id="userMenuDropdown" role="menu" aria-hidden="true">

              <div class="user-menu-header">
                <span class="user-menu-header-name"  id="dropdownDisplayName"><?= htmlspecialchars($menuName, ENT_QUOTES) ?></span>
                <span class="user-menu-header-uname" id="dropdownUsername" dir="ltr"><?= htmlspecialchars($email !== '' ? $email : $username, ENT_QUOTES) ?></span>
              </div>

              <div class="user-menu-divider"></div>

              <a href="/admin" class="user-menu-item user-menu-item--admin" id="adminPanelLink" role="menuitem"<?= $isAdmin ? '' : ' style="display:none;"' ?>>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                  <path d="M12 2l8 4v6c0 5-3.5 8-8 10-4.5-2-8-5-8-10V6l8-4z"/>
                  <path d="M9 12l2 2 4-4"/>
                </svg>
                پنل مدیریت
              </a>

              <a href="/notifications" class="user-menu-item" role="menuitem">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                  <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
                  <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
                </svg>
                اعلان‌ها
              </a>

              <a href="/profile" class="user-menu-item" role="menuitem">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                  <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>
                </svg>
                حساب کاربری
              </a>

              <div class="user-menu-divider"></div>

              <button class="user-menu-item user-menu-item--danger" id="logoutBtn" role="menuitem">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                  <path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4M16 17l5-5-5-5M21 12H9"/>
                </svg>
                خروج از حساب
              </button>

            </div>
            </div><!-- /.pop-shell -->
          </div>

        </div>

      </div>

      <!-- Full-width search bar (Telegram-style) — opened/closed via #searchToggle -->
      <div class="header-search" id="headerSearch" role="search">
        <svg class="header-search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
          <circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>
        </svg>
        <label for="search" class="sr-only">جستجوی ابزار</label>
        <input type="text" id="search" placeholder="جستجوی ابزار..."
          aria-label="جستجوی ابزار" aria-controls="toolsGrid"
          autocomplete="off" autocorrect="off" autocapitalize="off"
          spellcheck="false" maxlength="100" inputmode="search">
        <button type="button" class="clear-button" id="clearSearch" aria-label="پاک کردن جستجو" tabindex="-1">
          <svg viewBox="0 0 24 24" aria-hidden="true">
            <line x1="6" y1="6" x2="18" y2="18" stroke="white" stroke-width="2.5" stroke-linecap="round"/>
            <line x1="18" y1="6" x2="6" y2="18" stroke="white" stroke-width="2.5" stroke-linecap="round"/>
          </svg>
        </button>
        <button type="button" class="header-search-close" id="searchClose" title="بستن جستجو" aria-label="بستن جستجو">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M19 12H5M12 5l-7 7 7 7"/>
          </svg>
        </button>
      </div>
    </div>
  </header>

  <main role="main" id="main-content">
    <div class="filter-bar" id="filterBar" role="group" aria-label="فیلتر دسته‌بندی">
      <button class="chip active" data-filter="all">همه</button>
    </div>
    <div class="section-header">
      <span class="section-title">ابزارها</span>
      <span class="section-count" id="toolCount">0</span>
<?php if ($isAdmin): ?>
      <button type="button" class="reorder-toggle" id="reorderToggle" title="مرتب‌سازی کارت‌ها" aria-label="مرتب‌سازی کارت‌ها">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
          <line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/>
          <line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/>
        </svg>
        <span>مرتب‌سازی</span>
      </button>
<?php endif; ?>
    </div>
<?php if ($isAdmin): ?>
    <div class="reorder-bar" id="reorderBar" hidden>
      <span class="reorder-bar-msg">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
          <path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>
        </svg>
        کارت‌ها را بکشید و رها کنید، سپس ذخیره کنید
      </span>
      <div class="reorder-bar-actions">
        <button type="button" class="btn btn-secondary btn-sm" id="reorderCancel">لغو</button>
        <button type="button" class="btn btn-primary btn-sm" id="reorderSave">
          <span class="btn-spinner" aria-hidden="true"></span>
          <span>ذخیره ترتیب</span>
        </button>
      </div>
    </div>
<?php endif; ?>
    <div class="grid" id="toolsGrid" role="list" aria-label="لیست ابزارها" aria-live="polite">
      <?php for ($i = 0; $i < 6; $i++): ?>
      <div class="skeleton-card" aria-hidden="true">
        <div class="sk sk-icon"></div>
        <div class="sk sk-badge"></div>
        <div class="sk sk-title"></div>
        <div class="sk sk-line"></div>
        <div class="sk sk-line sk-line--short"></div>
      </div>
      <?php endfor; ?>
    </div>
  </main>

  <!-- ══════════════════════════════════════════════════════
       Notification detail modal
       Shared with notifications.php — see app/Views/partials/notif_detail_modal.php
       Content is filled by NotifDetail.open(n) in assets/js/notif-detail.js
       ══════════════════════════════════════════════════════ -->
  <?php include __DIR__ . '/app/Views/partials/notif_detail_modal.php'; ?>
  <!-- /Notification detail modal -->

  <footer class="app-footer">
    <span class="app-version" dir="ltr"><?= htmlspecialchars(app_version_label()) ?></span>
  </footer>

<?php if ($isAdmin): ?>
  <!-- ══ Add/edit tool modal (admin only) ══ -->
  <div class="tm-overlay" id="toolModal" aria-hidden="true">
    <div class="tm-dialog" role="dialog" aria-modal="true" aria-labelledby="tmHeadTitle">
      <div class="tm-head">
        <h3 id="tmHeadTitle">افزودن ابزار</h3>
        <button type="button" class="tm-close" id="tmClose" aria-label="بستن">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
      </div>
      <div class="tm-body">
        <input type="hidden" id="tmId" value="">
        <div class="tm-field">
          <label for="tmTitle">عنوان <span class="req">*</span></label>
          <div class="tm-field-input-wrap">
            <input type="text" id="tmTitle" maxlength="120" placeholder="نام ابزار">
            <span class="tm-field-counter-inline" id="tmTitleCounter" dir="ltr"><span id="tmTitleCount">0</span>/120</span>
          </div>
        </div>
        <div class="tm-field">
          <label for="tmDesc">توضیح</label>
          <div class="tm-field-input-wrap tm-field-input-wrap--textarea">
            <textarea id="tmDesc" rows="2" maxlength="300" placeholder="توضیح کوتاه"></textarea>
            <span class="tm-field-counter-inline" id="tmDescCounter" dir="ltr"><span id="tmDescCount">0</span>/300</span>
          </div>
        </div>
        <div class="tm-field">
          <label for="tmPath">آدرس / مسیر <span class="req">*</span></label>
          <input type="text" id="tmPath" dir="ltr" placeholder="tools/foo/ یا https://...">
        </div>
        <div class="tm-field">
          <label for="tmBadge">دسته‌بندی <span class="req">*</span></label>
          <div class="tm-combo" id="tmBadgeSelect">
            <div class="tm-combo-box">
              <input type="text" id="tmBadge" class="tm-combo-input" maxlength="20" placeholder="مثلا عمومی یا دسته جدید" autocomplete="off">
              <span class="tm-combo-counter" id="tmBadgeCounter" dir="ltr"><span id="tmBadgeCount">0</span>/20</span>
              <button type="button" class="tm-combo-toggle" id="tmBadgeToggle" aria-label="نمایش دسته‌های موجود" aria-haspopup="listbox" aria-expanded="false">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
              </button>
            </div>
            <div class="tm-combo-menu" id="tmBadgeMenu" role="listbox"></div>
          </div>
        </div>
        <div class="tm-field">
          <label for="tmIcon">آیکون</label>
          <div class="tm-combo" id="tmIconSelect">
            <div class="tm-combo-box">
              <span class="tm-combo-icon-preview" id="tmIconPreview" aria-hidden="true"></span>
              <input type="text" id="tmIcon" class="tm-combo-input" placeholder="جستجوی آیکون..." autocomplete="off">
              <button type="button" class="tm-combo-toggle" id="tmIconToggle" aria-label="نمایش آیکون‌ها" aria-haspopup="listbox" aria-expanded="false">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
              </button>
            </div>
            <div class="tm-combo-menu tm-combo-menu--icons" id="tmIconMenu" role="listbox"></div>
          </div>
        </div>
        <div class="tm-field">
          <label for="tmDeco">طرح پس‌زمینه</label>
          <div class="tm-combo" id="tmDecoSelect">
            <div class="tm-combo-box">
              <input type="text" id="tmDeco" class="tm-combo-input" placeholder="جستجوی طرح..." autocomplete="off">
              <button type="button" class="tm-combo-toggle" id="tmDecoToggle" aria-label="نمایش طرح‌های پس‌زمینه" aria-haspopup="listbox" aria-expanded="false">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
              </button>
            </div>
            <div class="tm-combo-menu" id="tmDecoMenu" role="listbox"></div>
          </div>
        </div>
        <div class="tm-field">
          <label>رنگ کارت <span class="tm-label-opt">(اختیاری)</span></label>
          <div class="tm-color-presets" id="tmColorPresets">
            <button type="button" class="tm-preset tm-preset-reset active" data-color="" title="بدون رنگ اختصاصی" aria-label="بدون رنگ">
              <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
            <button type="button" class="tm-preset" style="background:#3e7de7" data-color="#3e7de7" aria-label="آبی"></button>
            <button type="button" class="tm-preset" style="background:#8b5cf6" data-color="#8b5cf6" aria-label="بنفش"></button>
            <button type="button" class="tm-preset" style="background:#0ea472" data-color="#0ea472" aria-label="سبز"></button>
            <button type="button" class="tm-preset" style="background:#f59e0b" data-color="#f59e0b" aria-label="نارنجی"></button>
            <button type="button" class="tm-preset" style="background:#ef4444" data-color="#ef4444" aria-label="قرمز"></button>
            <button type="button" class="tm-preset" style="background:#ec4899" data-color="#ec4899" aria-label="صورتی"></button>
            <label class="tm-preset-custom" title="رنگ دلخواه">
              <input type="color" id="tmColor" value="#3e7de7">
              <span>دلخواه</span>
            </label>
          </div>
        </div>
        <div class="tm-field">
          <label>پیش‌نمایش</label>
          <div class="tm-preview" id="tmPreview">
            <div class="tm-preview-icon" id="tmPrevIcon"></div>
            <span class="tm-preview-badge" id="tmPrevBadge">ابزار</span>
            <div class="tm-preview-title" id="tmPrevTitle">عنوان ابزار</div>
            <div class="tm-preview-desc" id="tmPrevDesc">توضیح کوتاه</div>
            <div class="tm-preview-deco" id="tmPrevDeco" aria-hidden="true"></div>
          </div>
        </div>
      </div>
      <div class="tm-foot">
        <button type="button" class="btn btn-secondary btn-sm tm-btn-cancel" id="tmCancel">انصراف</button>
        <button type="button" class="btn btn-primary btn-sm tm-btn-save" id="tmSave">
          <span class="btn-spinner" aria-hidden="true"></span>
          <span class="tm-save-label">ذخیره</span>
        </button>
      </div>
    </div>
  </div>

  <!-- ══ Delete confirmation modal (admin only) ══ -->
  <div class="tm-overlay tm-confirm" id="toolConfirm" aria-hidden="true">
    <div class="tm-dialog tm-dialog-sm" role="dialog" aria-modal="true" aria-labelledby="tmConfirmTitle">
      <div class="tm-head">
        <h3 id="tmConfirmTitle">حذف ابزار</h3>
        <button type="button" class="tm-close" id="tmConfirmClose" aria-label="بستن">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
            <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
          </svg>
        </button>
      </div>
      <div class="tm-body tm-confirm-body">
        <div class="tm-confirm-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
            <polyline points="3 6 5 6 21 6"/>
            <path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/>
            <path d="M10 11v6M14 11v6M9 6V4a1 1 0 011-1h4a1 1 0 011 1v2"/>
          </svg>
        </div>
        <h4 class="tm-confirm-heading">آیا از حذف این ابزار اطمینان دارید؟</h4>
        <p class="tm-confirm-desc" id="tmConfirmDesc"></p>
        <div class="tm-confirm-warn">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
          این ابزار از داشبورد تمام کاربران حذف خواهد شد.
        </div>
      </div>
      <div class="tm-foot tm-confirm-foot">
        <button type="button" class="btn btn-secondary btn-sm" id="tmConfirmCancel">انصراف</button>
        <button type="button" class="btn btn-danger btn-sm" id="tmConfirmOk">حذف ابزار</button>
      </div>
    </div>
  </div>

  <!-- ══ Unsaved changes confirmation modal ══ -->
  <div class="tm-overlay" id="toolUnsaved" aria-hidden="true">
    <div class="tm-dialog tm-dialog-sm" role="dialog" aria-modal="true">
      <div class="tm-head">
        <h3 id="tmUnsavedTitle">بستن فرم</h3>
        <button type="button" class="tm-close" data-act="tmHideUnsaved" aria-label="بستن">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
            <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
          </svg>
        </button>
      </div>
      <div class="tm-body tm-confirm-body">
        <div class="tm-confirm-icon" style="background:rgba(217,119,6,.1);color:#d97706;">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
            <path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
            <line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
          </svg>
        </div>
        <h4 class="tm-confirm-heading">تغییرات ذخیره نشده دارید</h4>
        <p class="tm-confirm-desc">در صورت خروج، تغییرات شما ذخیره نخواهد شد. آیا مایلید به تغییرات ادامه دهید؟</p>
      </div>
      <div class="tm-foot tm-confirm-foot">
        <button type="button" class="btn btn-secondary btn-sm" id="tmUnsavedDiscard">خیر</button>
        <button type="button" class="btn btn-primary btn-sm" id="tmUnsavedStay">بله</button>
      </div>
    </div>
  </div>
<?php endif; ?>

  <!-- Dashboard toast container (save/delete tool errors, etc.) -->
  <div class="toast-wrap" id="toastWrap" aria-live="assertive"></div>

  <script src="/assets/js/lightbox.js?v=<?= $v_lb ?>" defer></script>
  <script src="/assets/js/actions.js?v=<?= asset_v(__DIR__ . '/assets/js/actions.js') ?>" defer></script>
  <script src="/assets/js/notif-detail.js?v=<?= asset_v(__DIR__ . '/assets/js/notif-detail.js') ?>" defer></script>
  <script src="/assets/js/popover.js?v=<?= asset_v(__DIR__ . '/assets/js/popover.js') ?>" defer></script>
  <script src="/assets/js/script.js?v=<?= $v_js ?>" defer></script>
</body>
</html>