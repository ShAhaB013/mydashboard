<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════
// PublicRouter — مسیریابی endpointهای عمومی api.php (?action=…)
// قرینه Router پنل ادمین، اما برای کنترلرهای عمومی (بدون CSRF).
// ═══════════════════════════════════════════════════════════

class PublicRouter
{
    private AppController  $app;
    private AuthController $auth;
    private FeedController $feed;

    private const ROUTES = [
        // ── داده/نشست ─────────────────────────────────────────
        'bootstrap'         => [AppController::class,  'bootstrap'],
        'assets'            => [AppController::class,  'assets'],
        'tools'             => [AppController::class,  'tools'],
        'me'                => [AppController::class,  'me'],
        'logout'            => [AppController::class,  'logout'],

        // ── احراز هویت / حساب ─────────────────────────────────
        'login'             => [AuthController::class, 'login'],
        'change_password'   => [AuthController::class, 'changePassword'],

        // ── اعلان‌های عمومی ───────────────────────────────────
        'notifications'     => [FeedController::class, 'notifications'],
        'unread_count'      => [FeedController::class, 'unreadCount'],
        'mark_read'         => [FeedController::class, 'markRead'],
        'mark_all_read'     => [FeedController::class, 'markAllRead'],

        // ── نشست‌های فعال کاربر (خودش) ───────────────────────
        'my_sessions'                 => [AppController::class, 'mySessions'],
        'terminate_my_session'        => [AppController::class, 'terminateMySession'],
        'terminate_my_other_sessions' => [AppController::class, 'terminateMyOther'],
    ];

    public function __construct(AppController $app, AuthController $auth, FeedController $feed)
    {
        $this->app  = $app;
        $this->auth = $auth;
        $this->feed = $feed;
    }

    public function dispatch(string $action): void
    {
        if (!isset(self::ROUTES[$action])) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'msg' => 'action نامعتبر است'], JSON_UNESCAPED_UNICODE);
            return;
        }

        [$class, $method] = self::ROUTES[$action];
        $controller = match ($class) {
            AppController::class  => $this->app,
            AuthController::class => $this->auth,
            FeedController::class => $this->feed,
        };
        $controller->$method();
    }
}
