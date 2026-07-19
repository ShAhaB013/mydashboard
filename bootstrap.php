<?php
// ═══════════════════════════════════════════════════════════
// bootstrap.php — shared setup for every entry point (admin.php / api.php /
// index.php / login.php / notifications.php / profile.php)
//   • one single autoload map (single source; add new classes only here)
//   • global error boundary (see below — registered before anything else runs)
//   • load config
//   • connect DB
//   • start session (same user realm: dash_user)
// API entry points must define APP_API before requiring this file so
// a "DB unavailable" error is returned as JSON (not plain text).
// Return value: config array ($config).
// ═══════════════════════════════════════════════════════════
declare(strict_types=1);

// ── Autoload (single map for the whole project) ─────────
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
            'Logger'                 => $core . 'Logger.php',
            'ErrorHandler'           => $core . 'ErrorHandler.php',
            'AppException'           => $core . 'Exceptions/AppException.php',
            'ValidationException'    => $core . 'Exceptions/ValidationException.php',
            'NotFoundException'      => $core . 'Exceptions/NotFoundException.php',
            'DbException'            => $core . 'Exceptions/DbException.php',
            // ── Models ────────────────────────────────────
            'ToolModel'              => $mdl . 'ToolModel.php',
            'IconModel'              => $mdl . 'IconModel.php',
            'DecoModel'              => $mdl . 'DecoModel.php',
            'UserModel'              => $mdl . 'UserModel.php',
            'SettingsModel'          => $mdl . 'SettingsModel.php',
            'AccessModel'            => $mdl . 'AccessModel.php',
            'RateLimitModel'         => $mdl . 'RateLimitModel.php',
            'NotificationModel'      => $mdl . 'NotificationModel.php',
            'CategoryModel'          => $mdl . 'CategoryModel.php',
            'SessionModel'           => $mdl . 'SessionModel.php',
            'LogModel'               => $mdl . 'LogModel.php',
            // ── Controllers (admin panel) ──────────────────
            'ToolController'         => $ctl . 'ToolController.php',
            'IconController'         => $ctl . 'IconController.php',
            'DecoController'         => $ctl . 'DecoController.php',
            'UserController'         => $ctl . 'UserController.php',
            'AccessController'       => $ctl . 'AccessController.php',
            'NotificationController' => $ctl . 'NotificationController.php',
            'SessionController'      => $ctl . 'SessionController.php',
            'SettingsController'     => $ctl . 'SettingsController.php',
            'CategoryController'     => $ctl . 'CategoryController.php',
            'LogController'          => $ctl . 'LogController.php',
            // ── Controllers (public — api.php) ────────────
            'AppController'          => $ctl . 'AppController.php',
            'AuthController'         => $ctl . 'AuthController.php',
            'FeedController'         => $ctl . 'FeedController.php',
        ];
    }
    if (isset($map[$class])) require_once $map[$class];
});

// ── Global error boundary — registered as early as autoload allows ──
// Previously each entry point registered this itself (admin.php/api.php did;
// index.php/login.php/notifications.php/profile.php never did, so an error
// on those pages vanished with zero log entry), and always *after* requiring
// this file — too late to catch a broken bootstrap step, like config.php
// itself failing to load. Registering it here, before config.php is even
// read, means every entry point is covered and every failure mode below is
// caught and logged (Logger falls back to a file if the DB isn't up yet).
$isApiRequest = (defined('APP_API') && APP_API) || !empty($_GET['api']);
ErrorHandler::register($isApiRequest);

// ── Config (one level above webroot) ─────────────────────
$config = require dirname(__DIR__) . '/config.php';

// ── DB connection (error response depends on entry-point type) ──
try {
    DB::connect($config['db']);
} catch (Throwable $e) {
    // The DB itself is unreachable here, so Logger's own DB-backed write will
    // fail too — it falls back to the same fallback-file mechanism used for
    // any other DB failure, instead of this being the one outage that leaves
    // zero trace anywhere.
    Logger::error($e->getMessage(), [
        'file'     => $e->getFile(),
        'line'     => $e->getLine(),
        'category' => 'database',
        'fatal'    => true,
    ]);

    http_response_code(503);
    if (defined('APP_API') && APP_API) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'msg' => 'سرویس در دسترس نیست'], JSON_UNESCAPED_UNICODE);
    } else {
        die('سرویس در دسترس نیست');
    }
    exit;
}

// ── Encryption key for recoverable sensitive values in the DB (e.g. smtp_pass) ──
// Missing this key in config.php doesn't cause an error; encryption just
// stays disabled (Crypto acts as a passthrough).
Crypto::init((string) ($config['crypto']['key'] ?? ''));

// ── Session (same user realm) ─────────────────────────────
UserSession::start();

// ── CSP nonce (unique per request) + CSP header ───────────
// Per-request nonce that all inline <script>s read from csp_nonce() so
// script-src can be kept without 'unsafe-inline'. The single source for the
// CSP header is here too (instead of .htaccess) since the nonce is dynamic.
$GLOBALS['csp_nonce'] = base64_encode(random_bytes(16));
if (!function_exists('csp_nonce')) {
    function csp_nonce(): string { return (string) ($GLOBALS['csp_nonce'] ?? ''); }
}
// CSP is enforcing: all inline handlers were moved to event-delegation and all
// inline <script>s have a nonce, so script-src is safe without 'unsafe-inline'.
// style-src still has 'unsafe-inline' for now (many style attributes; separate migration).
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
