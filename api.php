<?php
// ═══════════════════════════════════════════════════════════
// api.php — public endpoint (thin entry point)
// Routes to public controllers via PublicRouter (?action=…):
//   AppController : bootstrap / assets / tools / me / logout
//   AuthController: login / change_password
//   FeedController: notifications / unread_count / mark_read / mark_all_read
// ═══════════════════════════════════════════════════════════
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

// ── Shared bootstrap: autoload + config + DB + session ────
// APP_API → DB errors are returned as JSON.
define('APP_API', true);

// Global error boundary: any uncaught Throwable, instead of leaking a
// stack trace, is logged server-side and returned to the client as a clean JSON 500.
try {
    $config = require __DIR__ . '/bootstrap.php';

    // ── CSRF validation (method-based — resilient to drift) ──
    // Every POST request from a logged-in user requires a valid X-CSRF-Token header.
    // This "default-deny for POST" model, instead of a manual allowlist, guarantees
    // that every new state-changing action is automatically protected too. Exceptions:
    //   • login: no session/token exists yet (protected by rate-limiting instead).
    //   • guest (no session): nothing to forge; handlers return 401 themselves.
    // Read-only endpoints (bootstrap/tools/notifications/…) are called via GET and
    // never enter this condition at all.
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

    // ── Routing ────────────────────────────────────────────────
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
