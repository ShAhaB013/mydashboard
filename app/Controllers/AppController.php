<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════
// AppController — endpointهای عمومی داده/نشست
//   bootstrap / assets / tools / me / logout
// (منطق عینا از api.php منتقل شده؛ سرفصل‌های caching/ETag/304 حفظ شده‌اند)
// ═══════════════════════════════════════════════════════════

class AppController
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    // ── bootstrap: همه داده اولیه در یک درخواست ──────────────
    // me + assets + tools + notifications + unread_count
    public function bootstrap(): void
    {
        $config     = $this->config;
        $isLoggedIn = UserSession::check();

        // assets
        $iconsFile = $config['files']['icons'];
        $decosFile = $config['files']['decos'];
        $iconDb    = new JsonStore($iconsFile);
        $decoDb    = new JsonStore($decosFile);
        $assets    = ['ok' => true, 'icons' => $iconDb->all(), 'decos' => $decoDb->all()];

        // me
        if ($isLoggedIn) {
            $me = [
                'ok'           => true,
                'logged_in'    => true,
                'display_name' => $_SESSION['display_name'] ?? $_SESSION['username'] ?? '',
                'username'     => $_SESSION['username'] ?? '',
                'phone'        => $_SESSION['phone'] ?? '',
                'is_admin'     => (($_SESSION['role'] ?? 'user') === 'admin'),
            ];
        } else {
            $me = ['ok' => true, 'logged_in' => false];
        }

        // tools — ادمین همه ابزارها (شامل خصوصی) را می‌بیند تا بتواند روی همان داشبورد مدیریت کند
        $toolModel = new ToolModel();
        $isAdmin   = $isLoggedIn && (($_SESSION['role'] ?? 'user') === 'admin');
        $toolRows  = $isAdmin
            ? $toolModel->all()
            : ($isLoggedIn ? $toolModel->allForUser(UserSession::id()) : $toolModel->allPublic());
        $tools = ['ok' => true, 'tools' => ToolModel::toFrontend($toolRows)];

        // unread (سبک): لیست کامل اعلان‌ها دیگر در bootstrap حمل نمی‌شود تا کارت‌ها
        // منتظر دانلود ~۱۰۵KB اعلان نمانند. لیست به‌صورت تنبل (action=notifications)
        // پس از رندر کارت‌ها در پس‌زمینه لود می‌شود.
        // کاربر لاگین‌شده: شمارش ناخوانده با یک کوئری سبک محاسبه می‌شود تا بج فوری بیاید.
        // مهمان: شمارش سمت کلاینت (از localStorage) بعد از لود لیست محاسبه می‌شود.
        $unread = $isLoggedIn
            ? ['ok' => true, 'count' => (new NotificationModel())->unreadCount(UserSession::id())]
            : ['ok' => true, 'count' => 0];

        $payload = [
            'ok'     => true,
            'me'     => $me,
            'assets' => $assets,
            'tools'  => $tools,
            'unread' => $unread,
        ];

        // ETag/۳۰۴ برای هر دو حالت: در ناوبری بین صفحات اگر داده عوض نشده باشد،
        // به‌جای دانلود مجدد کل پاسخ (assets+tools) فقط یک ۳۰۴ برمی‌گردد.
        $body       = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $tag        = $isLoggedIn ? ('boot-u' . UserSession::id()) : 'boot-guest';
        $etag       = '"' . $tag . '-' . md5($body) . '"';
        $clientEtag = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';

        if ($isLoggedIn) {
            // وابسته به نشست: فقط برای همان مرورگر، با اجبار revalidate
            header('Cache-Control: private, max-age=0, must-revalidate');
        } else {
            // مهمان: قابل کش کوتاه‌مدت مشترک
            header('Cache-Control: public, max-age=30');
        }
        header('ETag: ' . $etag);

        if ($clientEtag === $etag) {
            http_response_code(304);
            exit;
        }
        echo $body;
    }

    // ── assets: آیکون‌ها + انیمیشن‌ها ─────────────────────────
    public function assets(): void
    {
        $config    = $this->config;
        $iconsFile = $config['files']['icons'];
        $decosFile = $config['files']['decos'];

        $mtime = max(
            file_exists($iconsFile) ? (int) filemtime($iconsFile) : 0,
            file_exists($decosFile) ? (int) filemtime($decosFile) : 0,
        );
        $etag = '"assets-' . $mtime . '"';

        $clientEtag = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
        if ($clientEtag === $etag) {
            http_response_code(304);
            exit;
        }

        $iconDb = new JsonStore($iconsFile);
        $decoDb = new JsonStore($decosFile);

        header('Cache-Control: public, max-age=3600');
        header('ETag: ' . $etag);

        echo json_encode([
            'ok'    => true,
            'icons' => $iconDb->all(),
            'decos' => $decoDb->all(),
        ], JSON_UNESCAPED_UNICODE);
    }

    // ── tools ─────────────────────────────────────────────────
    public function tools(): void
    {
        $toolModel  = new ToolModel();
        $isLoggedIn = UserSession::check();
        $isAdmin    = $isLoggedIn && (($_SESSION['role'] ?? 'user') === 'admin');

        $rows = $isAdmin
            ? $toolModel->all()
            : ($isLoggedIn ? $toolModel->allForUser(UserSession::id()) : $toolModel->allPublic());

        $body = json_encode([
            'ok'    => true,
            'tools' => ToolModel::toFrontend($rows),
        ], JSON_UNESCAPED_UNICODE);

        if ($isLoggedIn) {
            header('Cache-Control: private, no-store');
        } else {
            $etag       = '"tools-' . md5($body) . '"';
            $clientEtag = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
            if ($clientEtag === $etag) {
                http_response_code(304);
                exit;
            }
            header('Cache-Control: public, max-age=30');
            header('ETag: ' . $etag);
        }

        echo $body;
    }

    // ── me ───────────────────────────────────────────────────
    public function me(): void
    {
        if (UserSession::check()) {
            $resp = [
                'ok'           => true,
                'logged_in'    => true,
                'display_name' => $_SESSION['display_name'] ?? $_SESSION['username'] ?? '',
                'username'     => $_SESSION['username'] ?? '',
                'phone'        => $_SESSION['phone'] ?? '',
                'is_admin'     => (($_SESSION['role'] ?? 'user') === 'admin'),
            ];
            echo json_encode($resp, JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode(['ok' => true, 'logged_in' => false], JSON_UNESCAPED_UNICODE);
        }
    }

    // ── logout ───────────────────────────────────────────────
    public function logout(): void
    {
        UserSession::destroy();
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    }

    // ═══════════════════════════════════════════════════════════
    // نشست‌های فعال کاربر (خودش) — مانند «دستگاه‌های فعال» تلگرام.
    // همه به نشست کاربر جاری محدود است (مالکیت سرور-تضمین).
    // ═══════════════════════════════════════════════════════════

    /** فهرست نشست‌های فعال همین کاربر */
    public function mySessions(): void
    {
        if (!$this->requireLogin()) return;

        $cur  = session_id();
        $rows = SessionModel::active(UserSession::id());
        $out  = array_map(static function (array $r) use ($cur): array {
            return [
                'id'         => (string) $r['id'],
                'is_current' => hash_equals((string) $cur, (string) $r['id']),
                'device'     => SessionModel::describeAgent((string) ($r['user_agent'] ?? '')),
                'ip'         => (string) ($r['ip'] ?? ''),
                'last_seen'  => (int) $r['last_seen'],
                'expires_at' => (int) $r['expires_at'],
            ];
        }, $rows);

        header('Cache-Control: private, no-store');
        echo json_encode(['ok' => true, 'sessions' => $out], JSON_UNESCAPED_UNICODE);
    }

    /** پایان‌دادن به یکی از نشست‌های همین کاربر (فقط نشست خودش) */
    public function terminateMySession(): void
    {
        if (!$this->requirePost() || !$this->requireLogin()) return;

        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $id   = trim((string) ($body['session_id'] ?? ''));
        if ($id === '') {
            echo json_encode(['ok' => false, 'msg' => 'شناسه نشست نامعتبر است'], JSON_UNESCAPED_UNICODE);
            return;
        }
        $self = ($id === session_id());
        SessionModel::terminateOwned($id, UserSession::id());
        echo json_encode(['ok' => true, 'self' => $self], JSON_UNESCAPED_UNICODE);
    }

    /** پایان همه نشست‌های دیگر همین کاربر (خروج از سایر دستگاه‌ها) */
    public function terminateMyOther(): void
    {
        if (!$this->requirePost() || !$this->requireLogin()) return;

        $n = SessionModel::terminateUser(UserSession::id(), session_id());
        echo json_encode(['ok' => true, 'count' => $n], JSON_UNESCAPED_UNICODE);
    }

    // ── کمکی‌های گارد ──────────────────────────────────────────
    private function requirePost(): bool
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['ok' => false, 'msg' => 'Method Not Allowed']);
            return false;
        }
        return true;
    }

    private function requireLogin(): bool
    {
        if (!UserSession::check()) {
            http_response_code(401);
            echo json_encode(['ok' => false, 'msg' => 'ابتدا وارد شوید']);
            return false;
        }
        return true;
    }
}
