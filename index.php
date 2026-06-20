<?php
require_once __DIR__ . '/version.php';
require_once __DIR__ . '/admin/Core/UserSession.php';

// وضعیت ورود را سمت سرور می‌خوانیم تا هدر بدون «پرش به حالت مهمان» رندر شود
UserSession::start();
$isLoggedIn  = UserSession::check();
$displayName = $isLoggedIn ? UserSession::displayName() : '';
$username    = $isLoggedIn ? (string) ($_SESSION['username'] ?? '') : '';
$email       = $isLoggedIn ? (string) ($_SESSION['email'] ?? '') : '';
$isAdmin     = $isLoggedIn && UserSession::isAdmin();
$menuName    = $displayName !== '' ? $displayName : $username;
$avatarChar  = $menuName !== '' ? mb_substr($menuName, 0, 1, 'UTF-8') : '؟';

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
  <script>
    (function () {
      const saved = localStorage.getItem('theme');
      const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
      if (saved === 'dark' || (!saved && prefersDark)) {
        document.documentElement.setAttribute('data-theme', 'dark');
      }
    })();
  </script>
  <script src="/assets/js/theme.js?v=<?= $v_theme ?>" defer></script>
  <!-- پیش‌بارگذاری صفحات داخلی برای ناوبری سریع (هنگام hover/قصد کلیک) -->
  <script type="speculationrules">
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

  <header role="banner">
    <div class="header-container">
      <h1>داشبورد مجموعه ابزارهای کمکی</h1>
      <div class="header-actions">

        <!-- جستجو -->
        <div class="search-box" role="search">
          <label for="search" class="sr-only">جستجوی ابزار</label>
          <input type="text" id="search" placeholder="جستجو..."
            aria-label="جستجوی ابزار" aria-controls="toolsGrid"
            autocomplete="off" autocorrect="off" autocapitalize="off"
            spellcheck="false" maxlength="100" inputmode="search">
          <svg class="search-icon" viewBox="0 0 24 24" aria-hidden="true">
            <path d="M10 2a8 8 0 105.293 14.293l4.707 4.707 1.414-1.414-4.707-4.707A8 8 0 0010 2zm0 2a6 6 0 110 12A6 6 0 0110 4z"/>
          </svg>
          <button type="button" class="clear-button" id="clearSearch" aria-label="پاک کردن جستجو" tabindex="-1">
            <svg viewBox="0 0 24 24" aria-hidden="true">
              <line x1="6" y1="6" x2="18" y2="18" stroke="white" stroke-width="2.5" stroke-linecap="round"/>
              <line x1="18" y1="6" x2="6" y2="18" stroke="white" stroke-width="2.5" stroke-linecap="round"/>
            </svg>
          </button>
        </div>

        <!-- دکمه تم -->
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

        <!-- ── زنگ اعلان‌ها ── -->
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

          <!-- dropdown -->
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

            <!-- آیتم‌ها توسط JS ساخته می‌شوند -->
            <div class="notif-drop-body" id="notifDropdownBody" role="list">
              <div class="notif-drop-empty">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                  <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
                  <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
                </svg>
                <p>در حال بارگذاری...</p>
              </div>
            </div>

            <div class="notif-drop-footer">

              <!-- صفحه‌بندی -->
              <div id="notifPagination" class="notif-drop-pagination" style="display:none;" aria-label="صفحه‌بندی اعلان‌ها">
                <button class="notif-pag-arrow" id="notifPrevBtn" aria-label="صفحه قبل" disabled>
                  <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                    <polyline points="9 18 15 12 9 6"/>
                  </svg>
                </button>
                <span id="notifPageInfo" class="notif-pag-info"></span>
                <button class="notif-pag-arrow" id="notifNextBtn" aria-label="صفحه بعد">
                  <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                    <polyline points="15 18 9 12 15 6"/>
                  </svg>
                </button>
              </div>

              <!-- مشاهده همه -->
              <a href="/notifications" class="notif-drop-view-all">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                  <path d="M9 18l6-6-6-6"/>
                </svg>
                مشاهده همه
              </a>

            </div>

          </div>
        </div>
        <!-- /زنگ اعلان‌ها -->

        <!-- ناحیه auth — وضعیت اولیه سمت سرور رندر می‌شود (بدون پرش هنگام رفرش) -->
        <div class="auth-area">

          <a class="auth-btn" id="authBtn" href="/login" aria-label="ورود به حساب کاربری"<?= $isLoggedIn ? ' style="display:none;"' : '' ?>>
            <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
              <path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/>
              <circle cx="12" cy="7" r="4"/>
            </svg>
            ورود
          </a>

          <div class="user-menu-wrap" id="userMenuWrap" style="display:<?= $isLoggedIn ? 'flex' : 'none' ?>;">

            <button
              class="user-menu-btn"
              id="userMenuBtn"
              aria-haspopup="true"
              aria-expanded="false"
              aria-controls="userMenuDropdown">
              <span class="user-menu-avatar" id="userMenuAvatar"><?= $isLoggedIn ? htmlspecialchars($avatarChar, ENT_QUOTES) : '' ?></span>
              <span class="user-menu-name"   id="userMenuName"><?= htmlspecialchars($menuName, ENT_QUOTES) ?></span>
              <svg class="user-menu-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                <polyline points="6 9 12 15 18 9"/>
              </svg>
            </button>

            <div class="user-menu-dropdown" id="userMenuDropdown" role="menu" aria-hidden="true">

              <div class="user-menu-header">
                <span class="user-menu-header-name"  id="dropdownDisplayName"><?= htmlspecialchars($menuName, ENT_QUOTES) ?></span>
                <span class="user-menu-header-uname" id="dropdownUsername" dir="ltr"><?= htmlspecialchars($email, ENT_QUOTES) ?></span>
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
                پروفایل
              </a>

              <div class="user-menu-divider"></div>

              <button class="user-menu-item user-menu-item--danger" id="logoutBtn" role="menuitem">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                  <path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4M16 17l5-5-5-5M21 12H9"/>
                </svg>
                خروج از حساب
              </button>

            </div>
          </div>

        </div>

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
    </div>
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
       مودال جزئیات اعلان
       برای همه کاربران (مهمان و لاگین‌شده) قابل نمایش است
       محتوا توسط NotifDetail.open(n) در script.js پر می‌شود
       ══════════════════════════════════════════════════════ -->
  <div
    class="notif-detail-overlay"
    id="notifDetailModal"
    role="dialog"
    aria-modal="true"
    aria-labelledby="ndTitle">

    <div class="notif-detail-box">

      <!-- هدر -->
      <div class="notif-detail-head">
        <h2 class="notif-detail-head-title" id="ndTitle"></h2>
        <button
          class="notif-detail-close"
          id="notifDetailClose"
          aria-label="بستن">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
            <line x1="18" y1="6" x2="6" y2="18"/>
            <line x1="6" y1="6" x2="18" y2="18"/>
          </svg>
        </button>
      </div>

      <!-- بدنه -->
      <div class="notif-detail-body">

        <!-- تصویر — JS نمایش/مخفی می‌کند -->
        <div class="notif-detail-img-wrap" id="ndImageWrap" style="display:none;">
          <img id="ndImage" class="js-lightbox" src="" alt="" loading="lazy">
        </div>

        <!-- محتوا -->
        <div class="notif-detail-content">

          <!-- متن اعلان -->
          <div
            class="notif-detail-body-text"
            id="ndBody"
            style="display:none;"></div>

          <!-- متادیتا -->
          <div class="notif-detail-meta">

            <div class="notif-detail-meta-row">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <circle cx="12" cy="12" r="10"/>
                <polyline points="12 6 12 12 16 14"/>
              </svg>
              <span id="ndDate"></span>
            </div>

            <div class="notif-detail-meta-row notif-detail-expiry"
                 id="ndExpiry"
                 style="display:none;">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <circle cx="12" cy="12" r="10"/>
                <line x1="12" y1="8" x2="12" y2="12"/>
                <line x1="12" y1="16" x2="12.01" y2="16"/>
              </svg>
              <span></span>
            </div>

          </div>
        </div>
      </div>

      <!-- فوتر -->
      <div class="notif-detail-foot">
        <button
          class="notif-detail-close-btn"
          onclick="NotifDetail.close()">
          بستن
        </button>
        <!-- لینک تاریخچه — فقط برای کاربران لاگین‌شده نمایش داده می‌شود -->
        <a
          href="/notifications"
          class="notif-detail-view-all"
          id="ndViewAllLink"
          style="display:none;">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
            <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
            <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
          </svg>
          مشاهده همه اعلان‌ها
        </a>
      </div>

    </div>
  </div>
  <!-- /مودال جزئیات اعلان -->

  <footer class="app-footer">
    <span class="app-version" dir="ltr"><?= htmlspecialchars(app_version_label()) ?></span>
  </footer>

  <script src="/assets/js/lightbox.js?v=<?= $v_lb ?>" defer></script>
  <script src="/assets/js/script.js?v=<?= $v_js ?>" defer></script>
</body>
</html>