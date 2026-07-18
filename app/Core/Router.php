<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════
// Router — routes API requests to controllers
// ═══════════════════════════════════════════════════════════

class Router
{
    private Request                $request;
    private ToolController         $toolCtrl;
    private IconController         $iconCtrl;
    private DecoController         $decoCtrl;
    private UserController         $userCtrl;
    private AccessController       $accessCtrl;
    private NotificationController $notifCtrl;
    private SettingsController     $settingsCtrl;
    private SessionController      $sessionCtrl;
    private CategoryController     $categoryCtrl;
    private LogController          $logCtrl;

    private const ROUTES = [
        // ── tools ────────────────────────────────────────────
        'list_tools'    => [ToolController::class,         'listPaginated'],
        'add'           => [ToolController::class,         'add'],
        'edit'          => [ToolController::class,         'edit'],
        'delete'        => [ToolController::class,         'delete'],
        'reorder'       => [ToolController::class,         'reorder'],

        // ── icons ────────────────────────────────────────────
        'save_icon'     => [IconController::class,         'save'],
        'delete_icon'   => [IconController::class,         'delete'],

        // ── animations ───────────────────────────────────────
        'save_deco'     => [DecoController::class,         'save'],
        'delete_deco'   => [DecoController::class,         'delete'],

        // ── users ────────────────────────────────────────────
        'list_users'    => [UserController::class,         'list'],
        'add_user'      => [UserController::class,         'create'],
        'edit_user'     => [UserController::class,         'update'],
        'delete_user'   => [UserController::class,         'delete'],
        'toggle_user'   => [UserController::class,         'toggleActive'],

        // ── login blocks (rate limit) ────────────────────────
        'list_blocks'   => [UserController::class,         'listBlocks'],
        'unblock_ip'    => [UserController::class,         'unblockIp'],

        // ── access ───────────────────────────────────────────
        'get_access'    => [AccessController::class,       'get'],
        'set_access'    => [AccessController::class,       'set'],
        'badges'        => [AccessController::class,       'listBadges'],

        // ── notifications ────────────────────────────────────
        'list_notifications'          => [NotificationController::class, 'list'],
        'create_notification'         => [NotificationController::class, 'create'],
        'update_notification'         => [NotificationController::class, 'update'],
        'delete_notification'         => [NotificationController::class, 'delete'],
        'delete_notification_image'   => [NotificationController::class, 'deleteImage'],
        'upload_notification_image'   => [NotificationController::class, 'uploadImage'],
        'notification_readers'        => [NotificationController::class, 'readers'],

        // ── email/SMTP settings ──────────────────────────────
        'save_settings'    => [SettingsController::class, 'save'],
        'test_email'       => [SettingsController::class, 'sendTest'],
        'save_debug_mode'  => [SettingsController::class, 'saveDebugMode'],

        // ── users' active sessions ───────────────────────────
        'list_sessions'           => [SessionController::class, 'list'],
        'terminate_session'       => [SessionController::class, 'terminate'],
        'terminate_user_sessions' => [SessionController::class, 'terminateUser'],
        'terminate_other_sessions'=> [SessionController::class, 'terminateOthers'],
        'save_session_ttl'        => [SessionController::class, 'saveTtl'],

        // ── categories ───────────────────────────────────────
        'list_categories'   => [CategoryController::class, 'list'],
        'rename_category'   => [CategoryController::class, 'rename'],
        'delete_category'   => [CategoryController::class, 'delete'],

        // ── error logs ───────────────────────────────────────
        'list_logs'   => [LogController::class, 'list'],
        'delete_log'  => [LogController::class, 'delete'],
        'clear_logs'  => [LogController::class, 'clear'],
    ];

    public function __construct(
        Request                $request,
        ToolController         $toolCtrl,
        IconController         $iconCtrl,
        DecoController         $decoCtrl,
        UserController         $userCtrl,
        AccessController       $accessCtrl,
        NotificationController $notifCtrl,
        SettingsController     $settingsCtrl,
        SessionController      $sessionCtrl,
        CategoryController     $categoryCtrl,
        LogController          $logCtrl
    ) {
        $this->request      = $request;
        $this->toolCtrl     = $toolCtrl;
        $this->iconCtrl     = $iconCtrl;
        $this->decoCtrl     = $decoCtrl;
        $this->userCtrl     = $userCtrl;
        $this->accessCtrl   = $accessCtrl;
        $this->notifCtrl    = $notifCtrl;
        $this->settingsCtrl = $settingsCtrl;
        $this->sessionCtrl  = $sessionCtrl;
        $this->categoryCtrl = $categoryCtrl;
        $this->logCtrl      = $logCtrl;
    }

    public function dispatch(): void
    {
        $action = $this->request->query('api');

        if (!isset(self::ROUTES[$action])) {
            Response::error('عملیات ناشناخته');
            return;
        }

        [$controllerClass, $method] = self::ROUTES[$action];
        $controller = $this->resolveController($controllerClass);
        $controller->$method();
    }

    private function resolveController(string $class): object
    {
        return match ($class) {
            ToolController::class         => $this->toolCtrl,
            IconController::class         => $this->iconCtrl,
            DecoController::class         => $this->decoCtrl,
            UserController::class         => $this->userCtrl,
            AccessController::class       => $this->accessCtrl,
            NotificationController::class => $this->notifCtrl,
            SettingsController::class     => $this->settingsCtrl,
            SessionController::class      => $this->sessionCtrl,
            CategoryController::class     => $this->categoryCtrl,
            LogController::class          => $this->logCtrl,
            default => (function () {
                Response::error('کنترلر یافت نشد');
                exit;
            })(),
        };
    }
}