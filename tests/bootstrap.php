<?php
// ═══════════════════════════════════════════════════════════
// tests/bootstrap.php — راه‌اندازی محیط تست
//   • همان autoload map پروژه (بدون اجرای session/CSP سراسری bootstrap.php اصلی)
//   • اتصال DB مستقیم (برای assertion های white-box)
//   • Crypto::init با کلید config.php واقعی (برای تست‌های رمزنگاری)
// ═══════════════════════════════════════════════════════════
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

define('TESTS_ROOT', __DIR__);
define('APP_ROOT', dirname(__DIR__));

// ── autoload — همان نقشه‌ی bootstrap.php اصلی پروژه ──────────
spl_autoload_register(function (string $class): void {
    static $map = null;
    if ($map === null) {
        $core = APP_ROOT . '/app/Core/';
        $mdl  = APP_ROOT . '/app/Models/';
        $ctl  = APP_ROOT . '/app/Controllers/';
        $map = [
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
            'ToolModel'              => $mdl . 'ToolModel.php',
            'IconModel'              => $mdl . 'IconModel.php',
            'DecoModel'              => $mdl . 'DecoModel.php',
            'UserModel'              => $mdl . 'UserModel.php',
            'SettingsModel'          => $mdl . 'SettingsModel.php',
            'AccessModel'            => $mdl . 'AccessModel.php',
            'RateLimitModel'         => $mdl . 'RateLimitModel.php',
            'NotificationModel'      => $mdl . 'NotificationModel.php',
            'SessionModel'           => $mdl . 'SessionModel.php',
            'ToolController'         => $ctl . 'ToolController.php',
            'IconController'         => $ctl . 'IconController.php',
            'DecoController'         => $ctl . 'DecoController.php',
            'UserController'         => $ctl . 'UserController.php',
            'AccessController'       => $ctl . 'AccessController.php',
            'NotificationController' => $ctl . 'NotificationController.php',
            'SessionController'      => $ctl . 'SessionController.php',
            'SettingsController'     => $ctl . 'SettingsController.php',
            'AppController'          => $ctl . 'AppController.php',
            'AuthController'         => $ctl . 'AuthController.php',
            'FeedController'         => $ctl . 'FeedController.php',
        ];
    }
    if (isset($map[$class])) require_once $map[$class];
});

// ── config واقعی پروژه (یک سطح بالاتر از webroot) + config تست ──
$APP_CONFIG  = require APP_ROOT . '/../config.php';
$TEST_CONFIG = require TESTS_ROOT . '/config.php';

DB::connect($APP_CONFIG['db']);
Crypto::init((string) ($APP_CONFIG['crypto']['key'] ?? ''));

require TESTS_ROOT . '/lib/HttpClient.php';
require TESTS_ROOT . '/lib/Assert.php';
require TESTS_ROOT . '/lib/Fixtures.php';
require TESTS_ROOT . '/lib/Reporter.php';
require TESTS_ROOT . '/lib/helpers.php';

return ['app' => $APP_CONFIG, 'test' => $TEST_CONFIG];
