<?php
// ═══════════════════════════════════════════════════════════
// api.php — endpoint عمومی (نقطه ورود نازک)
// مسیریابی به کنترلرهای عمومی از طریق PublicRouter (?action=…):
//   AppController : bootstrap / assets / tools / me / logout
//   AuthController: login / change_password
//   FeedController: notifications / unread_count / mark_read / mark_all_read
// ═══════════════════════════════════════════════════════════
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

// ── Bootstrap مشترک: autoload + config + DB + session ────
// APP_API → خطای DB به‌صورت JSON پاسخ داده می‌شود.
define('APP_API', true);
$config = require __DIR__ . '/bootstrap.php';

// ── مسیریابی ─────────────────────────────────────────────
$router = new PublicRouter(
    new AppController($config),
    new AuthController(),
    new FeedController()
);
$router->dispatch(trim($_GET['action'] ?? ''));
