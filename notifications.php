<?php
// ═══════════════════════════════════════════════════════════
// notifications.php — notification history and search page
// ═══════════════════════════════════════════════════════════
declare(strict_types=1);
require_once __DIR__ . '/version.php';

// ── Shared bootstrap: autoload + config + DB + session ────
$config = require __DIR__ . '/bootstrap.php';

if (!UserSession::check()) {
    header('Location: /login');
    exit;
}

$userId  = UserSession::id();
$isAdmin = UserSession::isAdmin();

// CSRF token for the state-changing mark_read request
$csrfToken = UserSession::ensureCsrfToken();

// ── Pagination and search parameters ──────────────────────
// Two parallel paths: page=N (traditional OFFSET, for page numbers/go-to-page) or
// after=/before= (keyset, for adjacent Prev/Next arrows — fast at any depth).
$search  = trim($_GET['q']    ?? '');
$page    = max(1, (int) ($_GET['page'] ?? 1));
$afterCursor  = Cursor::decode(trim($_GET['after']  ?? ''));
$beforeCursor = Cursor::decode(trim($_GET['before'] ?? ''));
$useKeyset    = ($afterCursor !== null || $beforeCursor !== null || isset($_GET['after']) || isset($_GET['before']));
$keysetDir    = $beforeCursor !== null || isset($_GET['before']) ? 'prev' : 'next';
$keysetCursor = $keysetDir === 'prev' ? $beforeCursor : $afterCursor;
// Prev/Next cursor links carry the page they were rendered from as `cp`, so the
// display-only page number can be tracked across keyset navigation (cursor nav
// always moves exactly one page).
if ($useKeyset) {
    $knownPage = isset($_GET['cp']) ? max(1, (int) $_GET['cp']) : $page;
    $page = max(1, $knownPage + ($keysetDir === 'next' ? 1 : -1));
}

// Items per page — user-adjustable and persisted
// Priority: current selection in URL → saved cookie → default
$perPageAllowed = [10, 20, 50, 100];
$ppDefault      = 20;
$ppFromGet      = isset($_GET['pp']) ? (int) $_GET['pp'] : 0;
$ppFromCookie   = (int) ($_COOKIE['notif_pp'] ?? 0);
$perPage        = $ppFromGet ?: ($ppFromCookie ?: $ppDefault);
if (!in_array($perPage, $perPageAllowed, true)) {
    $perPage = $ppDefault;
}
// Save the user's choice for next time (client-side cookie → independent of login)
if ($ppFromGet && in_array($perPage, $perPageAllowed, true)) {
    setcookie('notif_pp', (string) $perPage, [
        'expires'  => time() + 60 * 60 * 24 * 365,
        'path'     => '/',
        'samesite' => 'Lax',
    ]);
}

// ── Advanced search filters (creation date + status) ──────
$fDateFrom = trim($_GET['df'] ?? '');
$fDateTo   = trim($_GET['dt'] ?? '');
$fStatus   = trim($_GET['st'] ?? '');
// Validate date and status format
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fDateFrom)) $fDateFrom = '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fDateTo))   $fDateTo   = '';
if (!in_array($fStatus, ['active', 'expired'], true)) $fStatus   = '';

$filters = [
    'date_from' => $fDateFrom,
    'date_to'   => $fDateTo,
    'status'    => $fStatus,
];
// Is any advanced filter active? (to keep the panel open)
$hasAdvanced = ($fDateFrom !== '' || $fDateTo !== '' || $fStatus !== '');

// ── Gregorian date helper ──
if (!function_exists('gregorian_datetime')) {
    function gregorian_datetime(int $ts): string
    {
        return date('Y/m/d H:i', $ts);
    }
}

// ── Page number range helper (matches _pageRange in notifications-admin.js) ──
if (!function_exists('notif_page_range')) {
    function notif_page_range(int $cur, int $count): array
    {
        if ($count <= 7) return range(1, $count);
        $out   = [1];
        $left  = max(2, $cur - 1);
        $right = min($count - 1, $cur + 1);
        if ($left > 2) $out[] = '...';
        for ($i = $left; $i <= $right; $i++) $out[] = $i;
        if ($right < $count - 1) $out[] = '...';
        $out[] = $count;
        return $out;
    }
}

// ── Fetch data (pagination path) ──
$nm = new NotificationModel();

$total = $nm->historyCountForUser($userId, $search, $filters, $isAdmin);
$pages = max(1, (int) ceil($total / $perPage));

$nextCursor = $prevCursor = null;

if ($useKeyset) {
    $items = $nm->historyForUserKeyset($userId, $keysetCursor, $keysetDir, $perPage, $search, $filters, $isAdmin);

    $hasMore = count($items) > $perPage;
    $items   = $keysetDir === 'prev' ? array_slice($items, -$perPage) : array_slice($items, 0, $perPage);

    if (!empty($items)) {
        $first = $items[0];
        $last  = $items[count($items) - 1];
        // A light (LIMIT 1) peek on both sides to determine whether a next/prev page
        // exists — clearer than inferring purely from the direction of the current request.
        $peekPrev = $nm->historyForUserKeyset($userId, Cursor::decode(Cursor::encode($first['created_at'], (int) $first['id'])), 'prev', 1, $search, $filters, $isAdmin);
        $peekNext = $nm->historyForUserKeyset($userId, Cursor::decode(Cursor::encode($last['created_at'], (int) $last['id'])), 'next', 1, $search, $filters, $isAdmin);
        if (!empty($peekPrev)) $prevCursor = Cursor::encode($first['created_at'], (int) $first['id']);
        if (!empty($peekNext)) $nextCursor = Cursor::encode($last['created_at'], (int) $last['id']);
    }
    $page = min($page, $pages);
} else {
    $page  = min($page, $pages);
    $items = $nm->historyForUser($userId, $page, $perPage, $search, $filters, $isAdmin);
    if ($page > 1) {
        $f = $items[0] ?? null;
        if ($f) $prevCursor = Cursor::encode($f['created_at'], (int) $f['id']);
    }
    if ($page < $pages) {
        $l = $items[count($items) - 1] ?? null;
        if ($l) $nextCursor = Cursor::encode($l['created_at'], (int) $l['id']);
    }
}

// Badges for each notification
$badgesMap = [];
foreach ($items as $item) {
    $badgesMap[$item['id']] = $nm->getBadges((int) $item['id']);
}

// Marking as read only happens when each notification is opened in the modal (via JS)

// ── Static asset versions ─────────────────────────────────
$vCss   = asset_v(__DIR__ . '/assets/css/style.css');
$vJs    = asset_v(__DIR__ . '/assets/js/script.js');
$vLightbox = asset_v(__DIR__ . '/assets/js/lightbox.js');
$vTheme = asset_v(__DIR__ . '/assets/js/theme.js');
$vDpJs  = asset_v(__DIR__ . '/assets/js/datepicker.js');
$vDpCss = asset_v(__DIR__ . '/assets/css/datepicker.css');
$vNotifCss = asset_v(__DIR__ . '/assets/css/notifications.css');
$vNotifJs  = asset_v(__DIR__ . '/assets/js/notifications.js');

// ── Notification data for JS (no image in the list) ───────
$notifJson = [];
foreach ($items as $item) {
    $notifJson[(int) $item['id']] = [
        'title'            => $item['title'],
        'body'             => $item['body']            ?? '',
        'image_path'       => $item['image_path']      ?? null,
        'thumbnail_path'   => $item['thumbnail_path']  ?? null,
        'created_at'       => $item['created_at'],
        'updated_at'       => $item['updated_at'] ?? $item['created_at'],
        'expires_at'       => (int)  ($item['expires_at'] ?? 0),
        'is_expired'       => (bool) ($item['is_expired']       ?? false),
        'target_all_users' => (bool) ($item['target_all_users'] ?? false),
        'badges'           => $badgesMap[$item['id']]  ?? [],
        'is_read'          => (bool) ($item['is_read']   ?? false),
        'is_edited'        => (bool) ($item['is_edited'] ?? false),
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
  <link rel="stylesheet" href="/assets/css/notifications.css?v=<?= $vNotifCss ?>">
  <link rel="stylesheet" href="/assets/css/pagination.css?v=<?= asset_v(__DIR__ . '/assets/css/pagination.css') ?>">
</head>
<body class="notif-page-wrap">

  <!-- ── Unified header (Telegram-style) ── -->
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

      <label class="notif-perpage" title="تعداد آیتم در هر صفحه">
        <span class="sr-only">تعداد در هر صفحه</span>
        <select name="pp" data-cselect data-change="submitForm" aria-label="تعداد آیتم در هر صفحه">
          <?php foreach ($perPageAllowed as $opt): ?>
            <option value="<?= $opt ?>"<?= $opt === $perPage ? ' selected' : '' ?>><?= $opt ?></option>
          <?php endforeach; ?>
        </select>
      </label>

      <button type="button" class="notif-adv-toggle<?= $hasAdvanced ? ' active' : '' ?>"
              id="notifAdvToggle" aria-expanded="<?= $hasAdvanced ? 'true' : 'false' ?>"
              aria-controls="notifAdvPanel" title="جستجوی پیشرفته">
        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
          <line x1="4" y1="6" x2="20" y2="6"/><line x1="7" y1="12" x2="17" y2="12"/><line x1="10" y1="18" x2="14" y2="18"/>
        </svg>
        <span>فیلتر</span>
      </button>

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
          // Row number accounting for pagination
          $rowIndex = ($page - 1) * $perPage;
        ?>
        <?php foreach ($items as $item):
          $isRead    = (bool) ($item['is_read']    ?? false);
          $isEdited  = (bool) ($item['is_edited']  ?? false);
          $isExpired = (bool) ($item['is_expired'] ?? false);
          $hasImage  = !empty($item['image_path']);
          $badges    = $badgesMap[$item['id']] ?? [];

          $rowCls = 'notif-row'
            . (!$isRead    ? ' unread'  : '')
            . ($isExpired  ? ' expired' : '');

          // Highlight search matches in the title only
          $titleHtml = htmlspecialchars($item['title']);
          if ($search !== '') {
              $safeQ     = preg_quote(htmlspecialchars($search), '/');
              $titleHtml = preg_replace('/(' . $safeQ . ')/iu', '<mark>$1</mark>', $titleHtml);
          }

          $ts            = strtotime($item['created_at']);
          $createdShamsi = gregorian_datetime($ts);
          $expiresTs     = (int) ($item['expires_at'] ?? 0);
          $expiresShamsi = $expiresTs > 0 ? gregorian_datetime($expiresTs) : '';
          $rowNumFa      = ++$rowIndex;
        ?>
          <article
            class="<?= $rowCls ?>"
            role="listitem"
            data-id="<?= (int) $item['id'] ?>"
            aria-label="<?= htmlspecialchars($item['title']) ?>"
            data-act="npOpen"
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
                <?php if ($item['target_all_users']): ?><span class="npill npill-all">عمومی</span><?php endif; ?>
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

            <div class="notif-row-action">
              <button
                class="notif-view-btn"
                data-act="npOpen"
                data-id="<?= (int) $item['id'] ?>"
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

      <div class="pagination-info">
        نمایش <?= number_format(($page - 1) * $perPage + 1) ?> تا <?= number_format($rowIndex) ?> از <?= number_format($total) ?> اعلان
      </div>

      <!-- Pagination: adjacent Prev/Next arrows use the cursor (keyset, fast at any
           depth); page numbers and "go to page" use page=N (OFFSET). -->
      <?php if ($pages > 1):
        $qStr = ($search ? '&q=' . urlencode($search) : '') . '&pp=' . $perPage
              . ($fDateFrom ? '&df=' . urlencode($fDateFrom) : '')
              . ($fDateTo   ? '&dt=' . urlencode($fDateTo)   : '')
              . ($fStatus   ? '&st=' . urlencode($fStatus)   : '');
      ?>
        <nav class="pagination" aria-label="صفحه‌بندی">
          <?php if ($prevCursor !== null): ?>
            <a class="pagination-btn" href="/notifications?before=<?= urlencode($prevCursor) . $qStr ?>&cp=<?= $page ?>" aria-label="صفحه قبل">
              <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
            </a>
          <?php else: ?>
            <span class="pagination-btn" aria-disabled="true"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg></span>
          <?php endif; ?>

          <?php foreach (notif_page_range($page, $pages) as $i): ?>
            <?php if ($i === '...'): ?>
              <span class="pagination-ellipsis">…</span>
            <?php elseif ($i === $page): ?>
              <span class="pagination-btn active" aria-current="page"><?= $i ?></span>
            <?php else: ?>
              <a class="pagination-btn" href="/notifications?page=<?= $i . $qStr ?>"><?= $i ?></a>
            <?php endif; ?>
          <?php endforeach; ?>

          <?php if ($nextCursor !== null): ?>
            <a class="pagination-btn" href="/notifications?after=<?= urlencode($nextCursor) . $qStr ?>&cp=<?= $page ?>" aria-label="صفحه بعد">
              <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>
            </a>
          <?php else: ?>
            <span class="pagination-btn" aria-disabled="true"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg></span>
          <?php endif; ?>

          <form class="pagination-goto" method="get" action="/notifications" id="notifGotoForm">
            <?php if ($search !== ''): ?><input type="hidden" name="q" value="<?= htmlspecialchars($search) ?>"><?php endif; ?>
            <input type="hidden" name="pp" value="<?= (int) $perPage ?>">
            <?php if ($fDateFrom !== ''): ?><input type="hidden" name="df" value="<?= htmlspecialchars($fDateFrom) ?>"><?php endif; ?>
            <?php if ($fDateTo   !== ''): ?><input type="hidden" name="dt" value="<?= htmlspecialchars($fDateTo) ?>"><?php endif; ?>
            <?php if ($fStatus   !== ''): ?><input type="hidden" name="st" value="<?= htmlspecialchars($fStatus) ?>"><?php endif; ?>
            <label class="pagination-goto-label" for="notifGotoInput">برو به صفحه</label>
            <span class="pagination-goto-field">
              <input type="number" name="page" id="notifGotoInput" min="1" max="<?= (int) $pages ?>"
                value="<?= (int) $page ?>" class="pagination-goto-input" aria-label="شماره صفحه">
              <span class="pagination-goto-stepper">
                <button type="button" class="pagination-goto-spin" tabindex="-1" aria-label="افزایش شماره صفحه"
                  data-act="notifGotoStep" data-dir="1">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="18 15 12 9 6 15"/></svg>
                </button>
                <button type="button" class="pagination-goto-spin" tabindex="-1" aria-label="کاهش شماره صفحه"
                  data-act="notifGotoStep" data-dir="-1">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="6 9 12 15 18 9"/></svg>
                </button>
              </span>
            </span>
          </form>
        </nav>
      <?php endif; ?>

    <?php endif; ?>
  </main>

  <!-- ══════════════════════════════════════════════
       Notification detail modal — image loads only when opened
       Shared with index.php — see app/Views/partials/notif_detail_modal.php
       ══════════════════════════════════════════════ -->
  <?php $notifModalShowViewAll = false; include __DIR__ . '/app/Views/partials/notif_detail_modal.php'; ?>

  <!-- Notification data and user state -->
  <script nonce="<?= csp_nonce() ?>">
    const NOTIFS       = <?= json_encode($notifJson, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
    window.CSRF_TOKEN  = <?= json_encode($csrfToken, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>;
  </script>

  <script src="/assets/js/actions.js?v=<?= asset_v(__DIR__ . '/assets/js/actions.js') ?>"></script>
  <script src="/assets/js/notif-detail.js?v=<?= asset_v(__DIR__ . '/assets/js/notif-detail.js') ?>"></script>
  <script src="/assets/js/notifications.js?v=<?= $vNotifJs ?>"></script>

</body>
</html>
