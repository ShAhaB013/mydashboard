<?php
// ═══════════════════════════════════════════════════════════
// bootstrap.php — راه‌اندازی مشترک برای نقاط ورود (admin.php / api.php / notifications.php)
//   • یک نقشه autoload واحد (منبع یگانه؛ افزودن کلاس جدید فقط همین‌جا)
//   • بارگذاری پیکربندی
//   • اتصال DB
//   • شروع نشست (همان realm کاربر: dash_user)
// نقاط ورود API باید پیش از require این فایل، APP_API را تعریف کنند تا
// خطای «DB در دسترس نیست» به‌صورت JSON پاسخ داده شود (نه متن ساده).
// مقدار بازگشتی: آرایه پیکربندی ($config).
// ═══════════════════════════════════════════════════════════
declare(strict_types=1);

// ── Autoload (نقشه یگانه برای کل پروژه) ────────────────
spl_autoload_register(function (string $class): void {
    static $map = null;
    if ($map === null) {
        $core = __DIR__ . '/app/Core/';
        $mdl  = __DIR__ . '/app/Models/';
        $ctl  = __DIR__ . '/app/Controllers/';
        $map = [
            // ── Core ──────────────────────────────────────
            'UserSession'            => $core . 'UserSession.php',
            'DbSessionHandler'       => $core . 'DbSessionHandler.php',
            'DB'                     => $core . 'DB.php',
            'JsonStore'              => $core . 'JsonStore.php',
            'Request'                => $core . 'Request.php',
            'Response'               => $core . 'Response.php',
            'Router'                 => $core . 'Router.php',
            'PublicRouter'           => $core . 'PublicRouter.php',
            'Validator'              => $core . 'Validator.php',
            'PasswordPolicy'         => $core . 'PasswordPolicy.php',
            'EmailValidator'         => $core . 'EmailValidator.php',
            'ImageProcessor'         => $core . 'ImageProcessor.php',
            'Mailer'                 => $core . 'Mailer.php',
            'RateLimiter'            => $core . 'RateLimiter.php',
            'ResendThrottle'         => $core . 'ResendThrottle.php',
            // ── Models ────────────────────────────────────
            'ToolModel'              => $mdl . 'ToolModel.php',
            'IconModel'              => $mdl . 'IconModel.php',
            'DecoModel'              => $mdl . 'DecoModel.php',
            'UserModel'              => $mdl . 'UserModel.php',
            'SettingsModel'          => $mdl . 'SettingsModel.php',
            'AccessModel'            => $mdl . 'AccessModel.php',
            'RateLimitModel'         => $mdl . 'RateLimitModel.php',
            'NotificationModel'      => $mdl . 'NotificationModel.php',
            'SessionModel'           => $mdl . 'SessionModel.php',
            // ── Controllers (پنل ادمین) ───────────────────
            'ToolController'         => $ctl . 'ToolController.php',
            'IconController'         => $ctl . 'IconController.php',
            'DecoController'         => $ctl . 'DecoController.php',
            'UserController'         => $ctl . 'UserController.php',
            'AccessController'       => $ctl . 'AccessController.php',
            'NotificationController' => $ctl . 'NotificationController.php',
            'SettingsController'     => $ctl . 'SettingsController.php',
            'SessionController'      => $ctl . 'SessionController.php',
            // ── Controllers (عمومی — api.php) ─────────────
            'AppController'          => $ctl . 'AppController.php',
            'AuthController'         => $ctl . 'AuthController.php',
            'FeedController'         => $ctl . 'FeedController.php',
        ];
    }
    if (isset($map[$class])) require_once $map[$class];
});

// ── پیکربندی (یک سطح بالاتر از webroot) ──────────────────
$config = require dirname(__DIR__) . '/config.php';

// ── اتصال DB (پاسخ خطا بسته به نوع نقطه ورود) ─────────
try {
    DB::connect($config['db']);
} catch (Throwable $e) {
    http_response_code(503);
    if (defined('APP_API') && APP_API) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'msg' => 'سرویس در دسترس نیست'], JSON_UNESCAPED_UNICODE);
    } else {
        die('سرویس در دسترس نیست');
    }
    exit;
}

// ── نشست (همان realm کاربر) ──────────────────────────────
UserSession::start();

return $config;
