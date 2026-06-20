<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════
// Router — مسیریابی درخواست‌های API به کنترلرها
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

    private const ROUTES = [
        // ── ابزارها ─────────────────────────────────────────
        'list_tools'    => [ToolController::class,         'listPaginated'],
        'add'           => [ToolController::class,         'add'],
        'edit'          => [ToolController::class,         'edit'],
        'delete'        => [ToolController::class,         'delete'],
        'reorder'       => [ToolController::class,         'reorder'],
        'toggle_public' => [ToolController::class,         'togglePublic'],

        // ── آیکون‌ها ─────────────────────────────────────────
        'save_icon'     => [IconController::class,         'save'],
        'delete_icon'   => [IconController::class,         'delete'],

        // ── انیمیشن‌ها ───────────────────────────────────────
        'save_deco'     => [DecoController::class,         'save'],
        'delete_deco'   => [DecoController::class,         'delete'],

        // ── کاربران ──────────────────────────────────────────
        'add_user'      => [UserController::class,         'create'],
        'edit_user'     => [UserController::class,         'update'],
        'delete_user'   => [UserController::class,         'delete'],
        'toggle_user'   => [UserController::class,         'toggleActive'],

        // ── انسداد ورود (Rate limit) ─────────────────────────
        'list_blocks'   => [UserController::class,         'listBlocks'],
        'unblock_ip'    => [UserController::class,         'unblockIp'],

        // ── دسترسی‌ها ────────────────────────────────────────
        'get_access'    => [AccessController::class,       'get'],
        'set_access'    => [AccessController::class,       'set'],
        'badges'        => [AccessController::class,       'listBadges'],

        // ── اعلان‌ها ──────────────────────────────────────────
        'list_notifications'          => [NotificationController::class, 'list'],
        'create_notification'         => [NotificationController::class, 'create'],
        'update_notification'         => [NotificationController::class, 'update'],
        'delete_notification'         => [NotificationController::class, 'delete'],
        'delete_notification_image'   => [NotificationController::class, 'deleteImage'],
        'upload_notification_image'   => [NotificationController::class, 'uploadImage'],

        // ── تنظیمات ایمیل/SMTP ──────────────────────────────
        'save_settings' => [SettingsController::class, 'save'],
        'test_email'    => [SettingsController::class, 'sendTest'],
    ];

    public function __construct(
        Request                $request,
        ToolController         $toolCtrl,
        IconController         $iconCtrl,
        DecoController         $decoCtrl,
        UserController         $userCtrl,
        AccessController       $accessCtrl,
        NotificationController $notifCtrl,
        SettingsController     $settingsCtrl
    ) {
        $this->request      = $request;
        $this->toolCtrl     = $toolCtrl;
        $this->iconCtrl     = $iconCtrl;
        $this->decoCtrl     = $decoCtrl;
        $this->userCtrl     = $userCtrl;
        $this->accessCtrl   = $accessCtrl;
        $this->notifCtrl    = $notifCtrl;
        $this->settingsCtrl = $settingsCtrl;
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
            default => (function () {
                Response::error('کنترلر یافت نشد');
                exit;
            })(),
        };
    }
}