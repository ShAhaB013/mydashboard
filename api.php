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

    // ── تایید CSRF (متدمحور — مقاوم در برابر drift) ──────────
    // هر درخواستِ POSTِ یک کاربرِ لاگین‌شده نیازمند هدرِ معتبر X-CSRF-Token است.
    // این مدلِ «default-deny برای POST» به‌جای allowlistِ دستی، تضمین می‌کند که
    // هر actionِ حالت‌تغییردهنده‌ی جدید هم خودبه‌خود محافظت شود. استثناها:
    //   • login: هنوز نشست/توکنی وجود ندارد (با rate-limit محافظت می‌شود).
    //   • مهمان (بدون نشست): چیزی برای جعل وجود ندارد؛ handlerها خودشان 401 می‌دهند.
    // endpointهای خواندنی (bootstrap/tools/notifications/…) با GET صدا زده می‌شوند و
    // اصلا وارد این شرط نمی‌شوند.
    $action = trim($_GET['action'] ?? '');
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action !== 'login' && UserSession::check()) {
        $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        $real = $_SESSION['csrf_token'] ?? '';
        if ($real === '' || !is_string($sent) || !hash_equals($real, $sent)) {
            http_response_code(403);
            echo json_encode(
                ['ok' => false, 'msg' => 'توکن امنیتی نامعتبر است. صفحه را تازه کنید و دوباره تلاش کنید.'],
                JSON_UNESCAPED_UNICODE
            );
            return;
        }
    }

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
