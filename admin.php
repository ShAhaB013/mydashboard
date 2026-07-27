<?php
// ═══════════════════════════════════════════════════════════
// admin.php — entry point for the admin panel
// Single login: same user session (dash_user). Only role='admin'
// users are allowed in. Access level is re-checked fresh from the DB
// on every request (we don't rely on the cached value in the session).
// ═══════════════════════════════════════════════════════════
declare(strict_types=1);

// ── Shared bootstrap: autoload + config + DB + session ────
$config = require __DIR__ . '/bootstrap.php';

// ── Project version (single source of truth) ─────────────
require_once __DIR__ . '/version.php';

$request = new Request();

$isApi = (bool) $request->query('api');

// Global error boundary is registered centrally in bootstrap.php (before
// config.php is even loaded), so every Throwable/warning from here on is
// already logged and given a clean response — nothing to do here.

// ── Logout ─────────────────────────────────────────────────
// POST + valid CSRF token only (prevents CSRF-logout via GET like <img src=?logout>).
// Normal user logout from the dashboard menu goes through api.php?action=logout.
if (isset($_GET['logout'])) {
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? '');
    $real = $_SESSION['csrf_token'] ?? '';
    if ($request->isPost() && $real !== '' && is_string($sent) && hash_equals($real, $sent)) {
        UserSession::destroy();
    }
    header('Location: /');
    exit;
}

// ── Auth gate + access level (source of truth: server) ───
// 1) must be logged in, 2) current role in DB must be admin and active.
$adminUser = null;
if (UserSession::check()) {
    $adminUser = (new UserModel())->findById(UserSession::id());
}
// TEMPORARY: auth.bypass (config.php) lets everyone into the admin panel
// while no real user accounts exist yet — see config.example.php.
$isAdmin = UserSession::bypassActive()
    || ($adminUser
        && ($adminUser['role'] ?? 'user') === 'admin'
        && (int) ($adminUser['is_active'] ?? 0) === 1);

if (!$isAdmin) {
    if ($isApi) {
        http_response_code(UserSession::check() ? 403 : 401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(
            ['ok' => false, 'msg' => 'دسترسی مجاز نیست'],
            JSON_UNESCAPED_UNICODE
        );
        exit;
    }
    // Page: unauthorized/unauthenticated user → dashboard (which itself gates on login)
    header('Location: /');
    exit;
}

// Ensure a CSRF token exists (old sessions may not have one)
UserSession::ensureCsrfToken();

// ── Build dependencies ────────────────────────────────────
$iconDb    = new JsonStore($config['files']['icons']);
$decoDb    = new JsonStore($config['files']['decos']);

$toolModel         = new ToolModel();
$iconModel         = new IconModel($iconDb, $config['protected_icons']);
$decoModel         = new DecoModel($decoDb, $config['protected_decos']);
$userModel         = new UserModel();
$accessModel       = new AccessModel();
$notificationModel = new NotificationModel();
$categoryModel     = new CategoryModel();
$logModel          = new LogModel();

// ── API routing ────────────────────────────────────────────
if ($isApi) {

    // ── CSRF validation: every API request needs a valid header ──
    $sentToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $realToken = $_SESSION['csrf_token'] ?? '';
    if ($realToken === '' || !is_string($sentToken) || !hash_equals($realToken, $sentToken)) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(
            ['ok' => false, 'msg' => 'توکن امنیتی نامعتبر است. صفحه را تازه کنید و دوباره تلاش کنید.'],
            JSON_UNESCAPED_UNICODE
        );
        exit;
    }

    $toolCtrl   = new ToolController($toolModel, $request);
    $iconCtrl   = new IconController($iconModel, $toolModel, $request);
    $decoCtrl   = new DecoController($decoModel, $toolModel, $request);
    $userCtrl   = new UserController($userModel, $request);
    $accessCtrl = new AccessController($accessModel, $request);
    $notifCtrl  = new NotificationController($notificationModel, $request);
    $settingsCtrl = new SettingsController($request);
    $sessionCtrl  = new SessionController($request);
    $categoryCtrl = new CategoryController($categoryModel, $request);
    $logCtrl      = new LogController($logModel, $request);

    $router = new Router(
        $request,
        $toolCtrl,
        $iconCtrl,
        $decoCtrl,
        $userCtrl,
        $accessCtrl,
        $notifCtrl,
        $settingsCtrl,
        $sessionCtrl,
        $categoryCtrl,
        $logCtrl
    );
    $router->dispatch();
    exit;
}

// ── Page routing ───────────────────────────────────────────
$page = $request->query('page');

if ($page === 'notifications') {
    $availableBadges   = (new CategoryModel())->namesInUseByTools();
    $badgesJson        = json_encode($availableBadges, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
    $csrfToken         = $_SESSION['csrf_token'] ?? '';
    require __DIR__ . '/app/Views/notifications_view.php';
    exit;
}

if ($page === 'users') {
    // User list is loaded via AJAX (list_users) — server only builds initial page data.
    $sessionTtlHours = SettingsModel::getInt('session_ttl_hours', 1, 720, 24);
    // Access modal needs "all tools" — a lite version is injected
    $toolsLite  = json_encode(ToolModel::toLite($toolModel->all()), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
    $csrfToken  = $_SESSION['csrf_token'] ?? '';
    require __DIR__ . '/app/Views/users_view.php';
    exit;
}

if ($page === 'settings') {
    $settings  = SettingsModel::all();
    $csrfToken = $_SESSION['csrf_token'] ?? '';
    require __DIR__ . '/app/Views/settings_view.php';
    exit;
}

if ($page === 'categories') {
    $csrfToken = $_SESSION['csrf_token'] ?? '';
    require __DIR__ . '/app/Views/categories_view.php';
    exit;
}

if ($page === 'logs') {
    // List is loaded via AJAX (list_logs) — server only builds initial page data.
    $debugMode = SettingsModel::get('debug_mode', '0');
    $csrfToken = $_SESSION['csrf_token'] ?? '';
    require __DIR__ . '/app/Views/logs_view.php';
    exit;
}

// ── Prepare data for the main dashboard ───────────────────
// Tools list is paginated client-side (admin.js), but the users/icons/
// decos/access sections need the full data, so everything is passed.
$tools     = $toolModel->all();
$icons     = $iconModel->all();
$decosData = $decoModel->all();

// Tools list is paginated server-side (admin.js → list_tools); so instead
// of injecting the full dataset plus a duplicate raw copy, only a "lite"
// version of all tools is injected (for sorting/access/icon+deco counts),
// and users not at all.
$toolsLite  = json_encode(ToolModel::toLite($tools), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
$toolsTotal = count($tools);
$usersTotal = $userModel->countAll();    // count only (no full user fetch)
$iconsJson  = json_encode($icons,     JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
$decosJson  = json_encode($decosData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
$csrfToken  = $_SESSION['csrf_token'] ?? '';

require __DIR__ . '/app/Views/dashboard.php';