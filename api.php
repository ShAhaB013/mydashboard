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

// مرز مدیریت خطای سراسری: هر Throwable ناگرفته به‌جای لو دادنِ stack trace،
// در لاگ سرور ثبت و به‌صورت JSON 500 تمیز به کلاینت پاسخ داده می‌شود.
try {
    $config = require __DIR__ . '/bootstrap.php';

    // ── مسیریابی ─────────────────────────────────────────────
    $router = new PublicRouter(
        new AppController($config),
        new AuthController(),
        new FeedController()
    );
    $router->dispatch(trim($_GET['action'] ?? ''));
} catch (Throwable $e) {
    error_log('[api] ' . $e);
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['ok' => false, 'msg' => 'خطای داخلی سرور رخ داد'], JSON_UNESCAPED_UNICODE);
}
