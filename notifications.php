<?php
// ═══════════════════════════════════════════════════════════
// notifications.php — صفحه تاریخچه و جستجوی اعلان‌ها
// برای کاربران لاگین‌کرده و مهمان‌ها
// ═══════════════════════════════════════════════════════════
declare(strict_types=1);
require_once __DIR__ . '/version.php';

// ── Bootstrap مشترک: autoload + config + DB + session ────
$config = require __DIR__ . '/bootstrap.php';

// ── وضعیت کاربر (مهمان یا لاگین‌کرده) ───────────────────
$isLoggedIn = UserSession::check();
$userId     = $isLoggedIn ? UserSession::id() : 0;

// ── پارامترهای صفحه‌بندی و جستجو ─────────────────────────
$search  = trim($_GET['q']    ?? '');
$page    = max(1, (int) ($_GET['page'] ?? 1));

// تعداد آیتم در هر صفحه — قابل تنظیم توسط کاربر و ماندگار (مهمان + لاگین‌کرده)
// اولویت: انتخاب جاری در URL → کوکی ذخیره‌شده → پیش‌فرض
$perPageAllowed = [10, 20, 50, 100];
$ppDefault      = 20;
$ppFromGet      = isset($_GET['pp']) ? (int) $_GET['pp'] : 0;
$ppFromCookie   = (int) ($_COOKIE['notif_pp'] ?? 0);
$perPage        = $ppFromGet ?: ($ppFromCookie ?: $ppDefault);
if (!in_array($perPage, $perPageAllowed, true)) {
    $perPage = $ppDefault;
}
// ذخیره انتخاب کاربر برای دفعات بعد (کوکی سمت‌کلاینت → مستقل از لاگین)
if ($ppFromGet && in_array($perPage, $perPageAllowed, true)) {
    setcookie('notif_pp', (string) $perPage, [
        'expires'  => time() + 60 * 60 * 24 * 365,
        'path'     => '/',
        'samesite' => 'Lax',
    ]);
}

// ── فیلترهای جستجوی پیشرفته (تاریخ ایجاد + وضعیت) ─────────
$fDateFrom = trim($_GET['df'] ?? '');
$fDateTo   = trim($_GET['dt'] ?? '');
$fStatus   = trim($_GET['st'] ?? '');
// اعتبارسنجی فرمت تاریخ و وضعیت
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fDateFrom)) $fDateFrom = '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fDateTo))   $fDateTo   = '';
if (!in_array($fStatus, ['active', 'expired'], true)) $fStatus   = '';

$filters = [
    'date_from' => $fDateFrom,
    'date_to'   => $fDateTo,
    'status'    => $fStatus,
];
// آیا فیلتر پیشرفته‌ای فعال است؟ (برای باز نگه‌داشتن پنل)
$hasAdvanced = ($fDateFrom !== '' || $fDateTo !== '' || $fStatus !== '');

// ── کمک‌تابع‌های تاریخ: تبدیل میلادی به شمسی + ارقام فارسی ──
if (!function_exists('g2j')) {
    function g2j(int $gy, int $gm, int $gd): array
    {
        $g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
        $gy2   = ($gm > 2) ? ($gy + 1) : $gy;
        $days  = 355666 + (365 * $gy) + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100)
               + intdiv($gy2 + 399, 400) + $gd + $g_d_m[$gm - 1];
        $jy    = -1595 + (33 * intdiv($days, 12053));
        $days %= 12053;
        $jy   += 4 * intdiv($days, 1461);
        $days %= 1461;
        if ($days > 365) { $jy += intdiv($days - 1, 365); $days = ($days - 1) % 365; }
        if ($days < 186) { $jm = 1 + intdiv($days, 31);       $jd = 1 + ($days % 31); }
        else             { $jm = 7 + intdiv($days - 186, 30); $jd = 1 + (($days - 186) % 30); }
        return [$jy, $jm, $jd];
    }
}
if (!function_exists('fa_digits')) {
    function fa_digits($s): string
    {
        return strtr((string) $s, ['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']);
    }
}
if (!function_exists('jalali_datetime')) {
    function jalali_datetime(int $ts): string
    {
        [$jy, $jm, $jd] = g2j((int) date('Y', $ts), (int) date('n', $ts), (int) date('j', $ts));
        return fa_digits(sprintf('%04d/%02d/%02d %s', $jy, $jm, $jd, date('H:i', $ts)));
    }
}

// ── واکشی داده (بر اساس وضعیت لاگین) ────────────────────
$nm = new NotificationModel();

if ($isLoggedIn) {
    $total = $nm->historyCountForUser($userId, $search, $filters);
    $pages = max(1, (int) ceil($total / $perPage));
    $page  = min($page, $pages);
    $items = $nm->historyForUser($userId, $page, $perPage, $search, $filters);
} else {
    $total = $nm->historyCountForGuest($search, $filters);
    $pages = max(1, (int) ceil($total / $perPage));
    $page  = min($page, $pages);
    $items = $nm->historyForGuest($page, $perPage, $search, $filters);
}

// badge های هر اعلان
$badgesMap = [];
foreach ($items as $item) {
    $badgesMap[$item['id']] = $nm->getBadges((int) $item['id']);
}

// علامت‌گذاری خوانده‌شده فقط هنگام باز کردن هر اعلان در modal (از طریق JS) انجام می‌شود
// مهمان‌ها: وضعیت خوانده‌شده از طریق localStorage مدیریت می‌شود

// ── ورژن فایل‌های استاتیک ────────────────────────────────
$vCss   = asset_v(__DIR__ . '/assets/css/style.css');
$vJs    = asset_v(__DIR__ . '/assets/js/script.js');
$vLightbox = asset_v(__DIR__ . '/assets/js/lightbox.js');
$vTheme = asset_v(__DIR__ . '/assets/js/theme.js');
$vDpJs  = asset_v(__DIR__ . '/assets/js/datepicker.js');
$vDpCss = asset_v(__DIR__ . '/assets/css/datepicker.css');
$vNotifCss = asset_v(__DIR__ . '/assets/css/notifications.css');
$vNotifJs  = asset_v(__DIR__ . '/assets/js/notifications.js');

// ── داده اعلان‌ها برای JS (بدون تصویر در لیست) ───────────
$notifJson = [];
foreach ($items as $item) {
    $notifJson[(int) $item['id']] = [
        'title'      => $item['title'],
        'body'       => $item['body']           ?? '',
        'image'      => $item['image_path']      ?? null,
        'thumbnail'  => $item['thumbnail_path']  ?? null,
        'created_at' => $item['created_at'],
        'updated_at' => $item['updated_at'] ?? $item['created_at'],
        'expires_at' => (int)  ($item['expires_at'] ?? 0),
        'is_expired' => (bool) ($item['is_expired']  ?? false),
        'is_public'  => (bool) ($item['is_public']   ?? false),
        'badges'     => $badgesMap[$item['id']]  ?? [],
        // مهمان: همیشه false — JS وضعیت را از localStorage می‌خواند
        'is_read'    => $isLoggedIn ? (bool) ($item['is_read']   ?? false) : false,
        'is_edited'  => $isLoggedIn ? (bool) ($item['is_edited'] ?? false) : false,
    ];
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <meta name="description" content="تاریخچه اعلان‌ها">
  <meta name="theme-color" content="#3e7de7">
  <meta name="color-scheme" content="light dark">
  <title>اعلان‌ها</title>
  <link rel="preload" href="fonts/vazir-font/Vazir-Variable.woff2" as="font" type="font/woff2" crossorigin="anonymous">
  <link rel="stylesheet" href="/assets/css/style.css?v=<?= $vCss ?>">
  <link rel="stylesheet" href="/assets/css/datepicker.css?v=<?= $vDpCss ?>">
  <script src="/assets/js/theme.js?v=<?= $vTheme ?>" defer></script>
  <script src="/assets/js/tooltip.js?v=<?= asset_v(__DIR__ . '/assets/js/tooltip.js') ?>" defer></script>
  <script src="/assets/js/lightbox.js?v=<?= $vLightbox ?>" defer></script>
  <script src="/assets/js/datepicker.js?v=<?= $vDpJs ?>" defer></script>
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
  <link rel="stylesheet" href="/assets/css/notifications.css?v=<?= $vNotifCss ?>">
</head>
<body class="notif-page-wrap">

  <!-- ── هدر یکپارچه (سبک تلگرام) ── -->
  <header class="app-header">
    <div class="app-header__inner">
      <div class="app-header__lead">
        <h1 class="app-header__title">اعلان‌ها</h1>
        <span class="app-header__count"><?= (int) $total ?></span>
      </div>
      <div class="app-header__actions">
        <a href="/" class="hdr-btn" title="بازگشت به داشبورد" aria-label="بازگشت به داشبورد">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M19 12H5M12 5l-7 7 7 7"/>
          </svg>
        </a>
      </div>
    </div>
  </header>

  <main class="notif-page-main" role="main">

    <!-- فرم جستجو -->
    <form class="notif-search-form" method="GET" action="/notifications" role="search">
      <div class="notif-search-wrap">
        <label for="notif-q" class="sr-only">جستجو در اعلان‌ها</label>
        <input
          type="text" id="notif-q" name="q"
          value="<?= htmlspecialchars($search) ?>"
          placeholder="جستجو در عنوان اعلان..."
          autocomplete="off" maxlength="100"
        >
        <svg class="notif-search-icon" viewBox="0 0 24 24" aria-hidden="true">
          <path d="M10 2a8 8 0 105.293 14.293l4.707 4.707 1.414-1.414-4.707-4.707A8 8 0 0010 2zm0 2a6 6 0 110 12A6 6 0 0110 4z"/>
        </svg>
      </div>
      <button type="submit" class="notif-search-btn">جستجو</button>
      <?php if ($search): ?>
        <a href="/notifications?pp=<?= $perPage ?>" class="notif-search-clear">پاک کردن</a>
      <?php endif; ?>

      <!-- تعداد در هر صفحه -->
      <label class="notif-perpage" title="تعداد آیتم در هر صفحه">
        <span class="sr-only">تعداد در هر صفحه</span>
        <select name="pp" data-cselect onchange="this.form.submit()" aria-label="تعداد آیتم در هر صفحه">
          <?php foreach ($perPageAllowed as $opt): ?>
            <option value="<?= $opt ?>"<?= $opt === $perPage ? ' selected' : '' ?>><?= $opt ?></option>
          <?php endforeach; ?>
        </select>
      </label>

      <!-- دکمه باز/بستن جستجوی پیشرفته -->
      <button type="button" class="notif-adv-toggle<?= $hasAdvanced ? ' active' : '' ?>"
              id="notifAdvToggle" aria-expanded="<?= $hasAdvanced ? 'true' : 'false' ?>"
              aria-controls="notifAdvPanel" title="جستجوی پیشرفته">
        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
          <line x1="4" y1="6" x2="20" y2="6"/><line x1="7" y1="12" x2="17" y2="12"/><line x1="10" y1="18" x2="14" y2="18"/>
        </svg>
        <span>فیلتر</span>
      </button>

      <!-- پنل جستجوی پیشرفته -->
      <div class="notif-adv-panel<?= $hasAdvanced ? ' open' : '' ?>" id="notifAdvPanel">
        <div class="notif-adv-field">
          <label for="adv-df">از تاریخ</label>
          <input type="date" id="adv-df" name="df" value="<?= htmlspecialchars($fDateFrom) ?>" dir="ltr" class="adv-date">
        </div>
        <div class="notif-adv-field">
          <label for="adv-dt">تا تاریخ</label>
          <input type="date" id="adv-dt" name="dt" value="<?= htmlspecialchars($fDateTo) ?>" dir="ltr" class="adv-date">
        </div>
        <div class="notif-adv-field">
          <label for="adv-st">وضعیت</label>
          <select id="adv-st" name="st" data-cselect>
            <option value=""<?= $fStatus === ''        ? ' selected' : '' ?>>همه</option>
            <option value="active"<?= $fStatus === 'active'  ? ' selected' : '' ?>>فعال</option>
            <option value="expired"<?= $fStatus === 'expired' ? ' selected' : '' ?>>منقضی‌شده</option>
          </select>
        </div>
        <div class="notif-adv-actions">
          <button type="submit" class="notif-adv-apply">اعمال فیلتر</button>
          <?php if ($hasAdvanced || $search): ?>
            <a href="/notifications?pp=<?= $perPage ?>" class="notif-adv-reset">حذف فیلترها</a>
          <?php endif; ?>
        </div>
      </div>
    </form>

    <!-- لیست ردیفی -->
    <?php if (empty($items)): ?>
      <div class="notif-empty">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
          <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
          <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
        </svg>
        <?php if ($search): ?>
          <p>نتیجه‌ای برای «<?= htmlspecialchars($search) ?>» یافت نشد.</p>
          <a href="/notifications">نمایش همه اعلان‌ها</a>
        <?php else: ?>
          <p>هیچ اعلانی برای نمایش وجود ندارد.</p>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <div class="notif-table" role="list" aria-label="لیست اعلان‌ها">

        <?php
          // شماره ردیف با احتساب صفحه‌بندی
          $rowIndex = ($page - 1) * $perPage;
        ?>
        <?php foreach ($items as $item):
          // لاگین‌کرده: از DB | مهمان: JS از localStorage می‌خواند (همیشه false در PHP)
          $isRead    = $isLoggedIn ? (bool) ($item['is_read']    ?? false) : false;
          $isEdited  = $isLoggedIn ? (bool) ($item['is_edited']  ?? false) : false;
          $isExpired = (bool) ($item['is_expired'] ?? false);
          $hasImage  = !empty($item['image_path']);
          $badges    = $badgesMap[$item['id']] ?? [];

          $rowCls = 'notif-row'
            . (!$isRead    ? ' unread'  : '')
            . ($isExpired  ? ' expired' : '');

          // هایلایت جستجو فقط در عنوان
          $titleHtml = htmlspecialchars($item['title']);
          if ($search !== '') {
              $safeQ     = preg_quote(htmlspecialchars($search), '/');
              $titleHtml = preg_replace('/(' . $safeQ . ')/iu', '<mark>$1</mark>', $titleHtml);
          }

          $ts            = strtotime($item['created_at']);
          $createdShamsi = jalali_datetime($ts);
          $expiresTs     = (int) ($item['expires_at'] ?? 0);
          $expiresShamsi = $expiresTs > 0 ? jalali_datetime($expiresTs) : '';
          $rowNumFa      = fa_digits(++$rowIndex);
        ?>
          <article
            class="<?= $rowCls ?>"
            role="listitem"
            data-id="<?= (int) $item['id'] ?>"
            aria-label="<?= htmlspecialchars($item['title']) ?>"
            onclick="NP.open(<?= (int) $item['id'] ?>)"
            style="cursor:pointer;"
          >
            <div class="notif-row-bar" aria-hidden="true"></div>

            <div class="notif-row-num" aria-hidden="true"><?= $rowNumFa ?></div>

            <div class="notif-row-body">
              <div class="notif-row-title"><?= $titleHtml ?></div>
              <div class="notif-row-meta">
                <?php if ($isEdited):       ?><span class="npill npill-edited">ویرایش شده</span>
                <?php elseif (!$isRead):    ?><span class="npill npill-unread">جدید</span><?php endif; ?>
                <?php if ($isExpired):  ?><span class="npill npill-expired">منقضی</span><?php endif; ?>
                <?php if ($item['is_public']): ?><span class="npill npill-public">عمومی</span><?php endif; ?>
                <?php if ($hasImage):   ?><span class="npill npill-img"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></svg>تصویر</span><?php endif; ?>
                <?php foreach ($badges as $b): ?>
                  <span class="npill npill-badge"><?= htmlspecialchars($b) ?></span>
                <?php endforeach; ?>
                <span class="notif-row-date" title="تاریخ انتشار">انتشار: <?= $createdShamsi ?></span>
                <?php if ($expiresTs > 0): ?>
                  <span class="notif-row-date" title="تاریخ انقضا"><?= $isExpired ? 'منقضی شد: ' : 'انقضا: ' ?><?= $expiresShamsi ?></span>
                <?php endif; ?>
              </div>
            </div>

            <div class="notif-row-action" onclick="event.stopPropagation()">
              <button
                class="notif-view-btn"
                onclick="NP.open(<?= (int) $item['id'] ?>)"
                aria-label="مشاهده اعلان <?= htmlspecialchars($item['title']) ?>"
              >
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                  <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                  <circle cx="12" cy="12" r="3"/>
                </svg>
                مشاهده
              </button>
            </div>

          </article>
        <?php endforeach; ?>

      </div>

      <!-- صفحه‌بندی -->
      <?php if ($pages > 1):
        $qStr = ($search ? '&q=' . urlencode($search) : '') . '&pp=' . $perPage
              . ($fDateFrom ? '&df=' . urlencode($fDateFrom) : '')
              . ($fDateTo   ? '&dt=' . urlencode($fDateTo)   : '')
              . ($fStatus   ? '&st=' . urlencode($fStatus)   : '');
      ?>
        <nav class="notif-pagination" aria-label="صفحه‌بندی">
          <?php if ($page > 1): ?>
            <a class="npag-btn" href="/notifications?page=<?= $page - 1 . $qStr ?>" aria-label="صفحه قبل">
              <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
            </a>
          <?php else: ?>
            <span class="npag-btn disabled"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg></span>
          <?php endif; ?>

          <?php $prevDots = false;
          for ($i = 1; $i <= $pages; $i++):
            $near = ($i === 1 || $i === $pages || abs($i - $page) <= 1);
            if (!$near): if (!$prevDots): $prevDots = true; ?><span class="npag-dots">…</span><?php endif; continue; endif;
            $prevDots = false; ?>
            <?php if ($i === $page): ?>
              <span class="npag-btn active" aria-current="page"><?= $i ?></span>
            <?php else: ?>
              <a class="npag-btn" href="/notifications?page=<?= $i . $qStr ?>"><?= $i ?></a>
            <?php endif; ?>
          <?php endfor; ?>

          <?php if ($page < $pages): ?>
            <a class="npag-btn" href="/notifications?page=<?= $page + 1 . $qStr ?>" aria-label="صفحه بعد">
              <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>
            </a>
          <?php else: ?>
            <span class="npag-btn disabled"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg></span>
          <?php endif; ?>
        </nav>
      <?php endif; ?>

    <?php endif; ?>
  </main>

  <!-- ══════════════════════════════════════════════
       Modal جزئیات اعلان — تصویر فقط هنگام باز شدن لود می‌شود
       ══════════════════════════════════════════════ -->
  <div
    class="nd-overlay"
    id="ndOverlay"
    role="dialog"
    aria-modal="true"
    aria-labelledby="ndTitle"
  >
    <div class="nd-box" tabindex="-1" style="outline:none;">

      <div class="nd-head">
        <h2 class="nd-head-title" id="ndTitle"></h2>
        <button class="nd-close-btn" id="ndCloseBtn" aria-label="بستن">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
            <line x1="18" y1="6" x2="6" y2="18"/>
            <line x1="6" y1="6" x2="18" y2="18"/>
          </svg>
        </button>
      </div>

      <div class="nd-body">

        <!-- تصویر: فقط هنگام open() لود می‌شود -->
        <div class="nd-image-wrap" id="ndImageWrap">
          <img id="ndImage" class="js-lightbox" src="" alt="" loading="lazy">
        </div>

        <div class="nd-content">
          <div class="nd-text" id="ndText"></div>

          <div class="nd-meta" id="ndMeta">
            <!-- ساخته شده توسط JS -->
          </div>
        </div>

      </div>

      <div class="nd-foot">
        <button class="nd-close-action" id="ndCloseAction">بستن</button>
      </div>

    </div>
  </div>

  <!-- داده اعلان‌ها و وضعیت کاربر -->
  <script>
    const NOTIFS       = <?= json_encode($notifJson, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
    const IS_LOGGED_IN = <?= $isLoggedIn ? 'true' : 'false' ?>;
  </script>

  <script src="/assets/js/notifications.js?v=<?= $vNotifJs ?>"></script>

  <footer class="app-footer">
    <span class="app-version" dir="ltr"><?= htmlspecialchars(app_version_label()) ?></span>
  </footer>

</body>
</html>
