<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════
// PublicRouter — routes public api.php endpoints (?action=…)
// Counterpart to the admin panel Router, but for public controllers (no CSRF).
// ═══════════════════════════════════════════════════════════

class PublicRouter
{
    private AppController  $app;
    private AuthController $auth;
    private FeedController $feed;

    private const ROUTES = [
        // ── data/session ──────────────────────────────────────
        'bootstrap'         => [AppController::class,  'bootstrap'],
        'assets'            => [AppController::class,  'assets'],
        'tools'             => [AppController::class,  'tools'],
        'me'                => [AppController::class,  'me'],
        'logout'            => [AppController::class,  'logout'],

        // ── auth / account ─────────────────────────────────────
        'login'             => [AuthController::class, 'login'],
        'forgot_password'   => [AuthController::class, 'forgotPassword'],
        'verify_reset_code' => [AuthController::class, 'verifyResetCode'],
        'reset_password'    => [AuthController::class, 'resetPassword'],
        'change_password'   => [AuthController::class, 'changePassword'],
        'update_my_name'    => [AuthController::class, 'updateMyName'],

        // ── public notifications ──────────────────────────────
        'notifications'     => [FeedController::class, 'notifications'],
        'unread_count'      => [FeedController::class, 'unreadCount'],
        'mark_read'         => [FeedController::class, 'markRead'],
        'mark_all_read'     => [FeedController::class, 'markAllRead'],

        // ── user's own active sessions ────────────────────────
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
