<?php
// ═══════════════════════════════════════════════════════════
// admin.php — نقطه ورود پنل مدیریت
// ورود واحد: همان سشن کاربر (dash_user). فقط کاربرهای role='admin'
// اجازه ورود دارند. سطح دسترسی روی هر درخواست به‌صورت تازه از DB
// چک می‌شود (به مقدار کش‌شده در سشن اتکا نمی‌کنیم).
// ═══════════════════════════════════════════════════════════
declare(strict_types=1);

// ── Bootstrap مشترک: autoload + config + DB + session ────
$config = require __DIR__ . '/bootstrap.php';

// ── نسخه پروژه (Single Source of Truth) ─────────────────
require_once __DIR__ . '/version.php';

$request = new Request();

$isApi = (bool) $request->query('api');

// ── خروج ─────────────────────────────────────────────────
if (isset($_GET['logout'])) {
    UserSession::destroy();
    header('Location: /');
    exit;
}

// ── گیت احراز هویت + سطح دسترسی (مرجع: سرور) ─────────────
// 1) باید لاگین باشد، 2) role فعلی در DB باید admin و فعال باشد.
$adminUser = null;
if (UserSession::check()) {
    $adminUser = (new UserModel())->findById(UserSession::id());
}
$isAdmin = $adminUser
    && ($adminUser['role'] ?? 'user') === 'admin'
    && (int) ($adminUser['is_active'] ?? 0) === 1;

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
    // صفحه: کاربر غیرمجاز/مهمان → داشبورد عمومی (ورود همان‌جاست)
    header('Location: /');
    exit;
}

// توکن CSRF را تضمین کن (سشن‌های قدیمی ممکن است نداشته باشند)
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ── ساخت وابستگی‌ها ──────────────────────────────────────
$iconDb    = new JsonStore($config['files']['icons']);
$decoDb    = new JsonStore($config['files']['decos']);

$toolModel         = new ToolModel();
$iconModel         = new IconModel($iconDb, $config['protected_icons']);
$decoModel         = new DecoModel($decoDb, $config['protected_decos']);
$userModel         = new UserModel();
$accessModel       = new AccessModel();
$notificationModel = new NotificationModel();

// ── مسیریابی API ─────────────────────────────────────────
if ($isApi) {

    // ── تایید CSRF: همه درخواست‌های API نیازمند هدر معتبرند ──
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

    $router = new Router(
        $request,
        $toolCtrl,
        $iconCtrl,
        $decoCtrl,
        $userCtrl,
        $accessCtrl,
        $notifCtrl,
        $settingsCtrl
    );
    $router->dispatch();
    exit;
}

// ── مسیریابی صفحات ───────────────────────────────────────
$page = $request->query('page');

if ($page === 'notifications') {
    $availableBadges   = $notificationModel->getAvailableBadges();
    $badgesJson        = json_encode($availableBadges, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
    $csrfToken         = $_SESSION['csrf_token'] ?? '';
    require __DIR__ . '/admin/Views/notifications_view.php';
    exit;
}

if ($page === 'users') {
    // ── صفحه‌بندی سمت سرور ─────────────────────────────────
    $perPage    = 15;
    $userPage   = max(1, (int) ($_GET['p'] ?? 1));
    $userSearch = trim((string) ($_GET['q'] ?? ''));

    $totalUsers = $userModel->countAll($userSearch);
    $userPages  = max(1, (int) ceil($totalUsers / $perPage));
    $userPage   = min($userPage, $userPages);

    $users      = $userModel->allPaginated($userPage, $perPage, $userSearch);
    // مودال دسترسی به «همهٔ ابزارها» نیاز دارد — نسخهٔ سبک تزریق می‌شود
    $toolsLite  = json_encode(ToolModel::toLite($toolModel->all()), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
    $csrfToken  = $_SESSION['csrf_token'] ?? '';
    require __DIR__ . '/admin/Views/users_view.php';
    exit;
}

if ($page === 'settings') {
    $settings  = SettingsModel::all();
    $csrfToken = $_SESSION['csrf_token'] ?? '';
    require __DIR__ . '/admin/Views/settings_view.php';
    exit;
}

// ── آماده‌سازی داده برای داشبورد اصلی ────────────────────
// لیست ابزارها سمت کلاینت صفحه‌بندی می‌شود (admin.js)، ولی بخش‌های
// کاربران/آیکون/دکو/دسترسی به داده کامل نیاز دارند، پس همه پاس می‌شوند.
$tools     = $toolModel->all();
$icons     = $iconModel->all();
$decosData = $decoModel->all();

// لیستِ ابزارها سمت سرور صفحه‌بندی می‌شود (admin.js → list_tools)؛ پس به‌جای
// تزریقِ کلِ دیتاستِ کامل + خامِ تکراری، فقط یک نسخهٔ «سبک» از همهٔ ابزارها
// تزریق می‌شود (برای مرتب‌سازی/دسترسی/شمارشِ آیکون‌ودکو) و کاربران اصلاً.
$toolsLite  = json_encode(ToolModel::toLite($tools), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
$toolsTotal = count($tools);
$usersTotal = $userModel->countAll();    // فقط شمارش (بدون واکشیِ کلِ کاربران)
$iconsJson  = json_encode($icons,     JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
$decosJson  = json_encode($decosData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
$csrfToken  = $_SESSION['csrf_token'] ?? '';

require __DIR__ . '/admin/Views/dashboard.php';