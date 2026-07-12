<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════
// AppController — public data/session endpoints
//   bootstrap / assets / tools / me / logout
// (logic moved verbatim from api.php; caching/ETag/304 headers preserved)
// ═══════════════════════════════════════════════════════════

class AppController
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    // ── bootstrap: all initial data in a single request ──────────────
    // me + assets + tools + notifications + unread_count
    public function bootstrap(): void
    {
        $isLoggedIn = UserSession::check();

        // ETag/304 for both cases: when navigating between pages, if the data hasn't changed,
        // only a 304 is returned instead of re-downloading the whole response (assets+tools).
        if ($isLoggedIn) {
            $body = $this->buildBootstrapBody(true);
            $tag  = 'boot-u' . UserSession::id();
            // Session-dependent: only for that same browser, forcing revalidation
            header('Cache-Control: private, max-age=0, must-revalidate');
        } else {
            // Guest: the response is identical across all visitors (no per-user data;
            // the guest's read-state is applied client-side from localStorage) — the server
            // micro-cache collapses N concurrent computations into 1, and stale-while-revalidate
            // smooths the request wave after the browser/proxy cache expires.
            $body = MicroCache::remember('boot-guest', 30, fn(): string => $this->buildBootstrapBody(false));
            $tag  = 'boot-guest';
            header('Cache-Control: public, max-age=30, stale-while-revalidate=30');
        }

        $etag       = '"' . $tag . '-' . md5($body) . '"';
        $clientEtag = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
        header('ETag: ' . $etag);

        if ($clientEtag === $etag) {
            http_response_code(304);
            exit;
        }
        echo $body;
    }

    /** Build the bootstrap body (me+assets+tools+unread) — for guests this is called from inside the micro-cache */
    private function buildBootstrapBody(bool $isLoggedIn): string
    {
        $config = $this->config;

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
                'first_name'   => $_SESSION['first_name'] ?? '',
                'last_name'    => $_SESSION['last_name'] ?? '',
                'username'     => $_SESSION['username'] ?? '',
                'phone'        => $_SESSION['phone'] ?? '',
                'email'        => $_SESSION['email'] ?? '',
                'is_admin'     => (($_SESSION['role'] ?? 'user') === 'admin'),
            ];
        } else {
            $me = ['ok' => true, 'logged_in' => false];
        }

        // tools — admin sees all tools (including private) so they can manage from the same dashboard
        $toolModel = new ToolModel();
        $isAdmin   = $isLoggedIn && (($_SESSION['role'] ?? 'user') === 'admin');
        $toolRows  = $isAdmin
            ? $toolModel->all()
            : ($isLoggedIn ? $toolModel->allForUser(UserSession::id()) : $toolModel->allPublic());
        $tools = ['ok' => true, 'tools' => ToolModel::toFrontend($toolRows)];

        // unread (light): the full notification list is no longer carried in bootstrap so the cards
        // don't have to wait for a ~105KB notification download. The list is loaded lazily
        // (action=notifications) in the background after the cards render.
        // Logged-in user: unread count is computed with a lightweight query so the badge appears immediately.
        // Guest: count is computed client-side (from localStorage) after the list loads.
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

        return (string) json_encode($payload, JSON_UNESCAPED_UNICODE);
    }

    // ── assets: icons + animations ─────────────────────────
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

        header('Cache-Control: public, max-age=3600, stale-while-revalidate=600');
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
        $isLoggedIn = UserSession::check();

        if ($isLoggedIn) {
            $toolModel = new ToolModel();
            $isAdmin   = ($_SESSION['role'] ?? 'user') === 'admin';
            $rows      = $isAdmin ? $toolModel->all() : $toolModel->allForUser(UserSession::id());
            $body      = json_encode([
                'ok'    => true,
                'tools' => ToolModel::toFrontend($rows),
            ], JSON_UNESCAPED_UNICODE);
            header('Cache-Control: private, no-store');
        } else {
            // Guest: public tools are identical for everyone — anti-stampede micro-cache
            $body = MicroCache::remember('tools-guest', 30, static function (): string {
                return (string) json_encode([
                    'ok'    => true,
                    'tools' => ToolModel::toFrontend((new ToolModel())->allPublic()),
                ], JSON_UNESCAPED_UNICODE);
            });

            $etag       = '"tools-' . md5($body) . '"';
            $clientEtag = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
            header('Cache-Control: public, max-age=30, stale-while-revalidate=30');
            header('ETag: ' . $etag);
            if ($clientEtag === $etag) {
                http_response_code(304);
                exit;
            }
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
                'first_name'   => $_SESSION['first_name'] ?? '',
                'last_name'    => $_SESSION['last_name'] ?? '',
                'username'     => $_SESSION['username'] ?? '',
                'phone'        => $_SESSION['phone'] ?? '',
                'email'        => $_SESSION['email'] ?? '',
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
    // Active sessions of the user (themselves) — like Telegram's "Active Devices".
    // Everything is scoped to the current user's session (server-enforced ownership).
    // ═══════════════════════════════════════════════════════════

    /** List of this user's active sessions */
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

    /** End one of this user's own sessions (only their own) */
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

    /** End all of this user's other sessions (log out of other devices) */
    public function terminateMyOther(): void
    {
        if (!$this->requirePost() || !$this->requireLogin()) return;

        $n = SessionModel::terminateUser(UserSession::id(), session_id());
        echo json_encode(['ok' => true, 'count' => $n], JSON_UNESCAPED_UNICODE);
    }

    // ── guard helpers ──────────────────────────────────────────
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
