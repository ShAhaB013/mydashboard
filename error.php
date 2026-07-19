<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════
// error.php — themed error page, wired via ErrorDocument (see .htaccess)
// for 403/404/500/503. Deliberately standalone: does NOT go through
// bootstrap.php (no autoload, no config, no DB, no session), because the
// whole point is to still render correctly when one of those is exactly
// what's broken (e.g. a missing config.php or a dead database).
// ═══════════════════════════════════════════════════════════

require __DIR__ . '/app/Core/ErrorPage.php';

$allowed = [403, 404, 500, 503];
$code = isset($_GET['code']) ? (int) $_GET['code'] : 500;
if (!in_array($code, $allowed, true)) {
    $code = 500;
}

ErrorPage::render($code);
