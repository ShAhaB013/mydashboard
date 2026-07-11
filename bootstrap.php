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
            'EmailDomainRules'       => $core . 'EmailDomainRules.php',
            'PasswordPolicy'         => $core . 'PasswordPolicy.php',
            'ImageProcessor'         => $core . 'ImageProcessor.php',
            'RateLimiter'            => $core . 'RateLimiter.php',
            'Mailer'                 => $core . 'Mailer.php',
            'ResendThrottle'         => $core . 'ResendThrottle.php',
            'Crypto'                 => $core . 'Crypto.php',
            'Cursor'                 => $core . 'Cursor.php',
            'MicroCache'             => $core . 'MicroCache.php',
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
            'SessionController'      => $ctl . 'SessionController.php',
            'SettingsController'     => $ctl . 'SettingsController.php',
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

// ── کلید رمزنگاریِ مقادیر حساسِ قابل‌بازیابی در DB (مثل smtp_pass) ──
// نبودِ این کلید در config.php باعث خطا نمی‌شود؛ فقط رمزنگاری غیرفعال
// می‌ماند (Crypto به‌صورت passthrough عمل می‌کند).
Crypto::init((string) ($config['crypto']['key'] ?? ''));

// ── نشست (همان realm کاربر) ──────────────────────────────
UserSession::start();

// ── CSP nonce (یکتا برای هر درخواست) + هدرِ CSP ──────────
// nonceِ per-request که همه‌ی <script>های inline از csp_nonce() می‌خوانند تا
// بتوان script-src را بدون 'unsafe-inline' نگه داشت. منبعِ یگانه‌ی هدرِ CSP هم
// همین‌جاست (به‌جای .htaccess) چون nonce داینامیک است.
$GLOBALS['csp_nonce'] = base64_encode(random_bytes(16));
if (!function_exists('csp_nonce')) {
    function csp_nonce(): string { return (string) ($GLOBALS['csp_nonce'] ?? ''); }
}
// CSP اجباری (enforcing): همه‌ی هندلرهای inline به event-delegation منتقل و همه‌ی
// <script>های inline nonce گرفته‌اند، پس script-src بدون 'unsafe-inline' امن است.
// style-src فعلاً 'unsafe-inline' دارد (صفاتِ style پرشمار؛ مهاجرتِ جداگانه).
if (!headers_sent()) {
    header(
        "Content-Security-Policy: "
        . "default-src 'self'; img-src 'self' data:; font-src 'self'; "
        . "style-src 'self' 'unsafe-inline'; "
        . "script-src 'self' 'nonce-" . csp_nonce() . "'; "
        . "object-src 'none'; base-uri 'self'; frame-ancestors 'self'; form-action 'self'"
    );
}

return $config;
