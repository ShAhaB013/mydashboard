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
        'register'          => [AuthController::class, 'register'],
        'check_email'       => [AuthController::class, 'checkEmail'],
        'verify_email'      => [AuthController::class, 'verifyEmail'],
        'resend_code'       => [AuthController::class, 'resendCode'],
        'forgot_password'   => [AuthController::class, 'forgotPassword'],
        'verify_reset_code' => [AuthController::class, 'verifyResetCode'],
        'reset_password'    => [AuthController::class, 'resetPassword'],
        'change_password'   => [AuthController::class, 'changePassword'],
        'request_email_change' => [AuthController::class, 'requestEmailChange'],
        'verify_email_change'  => [AuthController::class, 'verifyEmailChange'],
        'cancel_email_change'  => [AuthController::class, 'cancelEmailChange'],

        // ── اعلان‌های عمومی ───────────────────────────────────
        'notifications'     => [FeedController::class, 'notifications'],
        'unread_count'      => [FeedController::class, 'unreadCount'],
        'mark_read'         => [FeedController::class, 'markRead'],
        'mark_all_read'     => [FeedController::class, 'markAllRead'],
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
