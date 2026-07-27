<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════
// FeedController — logged-in user notifications (api.php route)
//   notifications / unread_count / mark_read / mark_all_read
// (logic moved verbatim from api.php; caching/ETag/304 preserved)
// ═══════════════════════════════════════════════════════════

class FeedController
{
    // ── notifications: active notifications visible to the current user (bell panel) ──
    //
    // Keyset-paginated (?before=<cursor>&limit=N — same Cursor encoding as the history
    // page) instead of returning a capped all-at-once list — the client infinite-scrolls
    // further pages in as needed, no arbitrary "bell shows at most N" limitation.
    //
    // Cheap ETag: since this is polled on every fresh panel-open, an O(1) watermark
    // (latest timestamp + total + unread count — each a single indexed lookup against
    // notification_recipients, no per-row scan) is hashed together with the requested
    // cursor/limit; the full query + badges + serialize only runs on a miss.
    public function notifications(): void
    {
        if (!UserSession::check()) {
            http_response_code(401);
            echo json_encode(['ok' => false, 'msg' => 'ابتدا وارد شوید']);
            return;
        }

        $this->notificationsForUser(UserSession::id());
    }

    // Logged-in user's feed — it's per-user and doesn't enter the shared micro-cache;
    // the same "cheap watermark → 304" pattern removes the cost of the frequent path.
    private function notificationsForUser(int $uid): void
    {
        $nm      = new NotificationModel();
        $isAdmin = UserSession::isAdmin();

        $perPage = max(1, min(50, (int) ($_GET['limit'] ?? 20)));
        $cursor  = Cursor::decode(trim($_GET['before'] ?? ''));

        $watermark = $nm->bellWatermark($uid, $isAdmin);
        $etagBase  = ($watermark['latest'] ?? '') . '|' . $watermark['total'] . '|' . $watermark['unread']
            . '|' . ($cursor !== null ? $cursor['created_at'] . ':' . $cursor['id'] : '') . '|' . $perPage;
        $etag       = '"notif-u' . $uid . '-' . md5($etagBase) . '"';
        $clientEtag = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';

        header('Cache-Control: private, max-age=0, must-revalidate');
        header('ETag: ' . $etag);

        if ($clientEtag === $etag) {
            http_response_code(304);
            exit;
        }

        // Only here (watermark mismatch) does the full query + badges + serialize run
        $page = $nm->bellFeed($uid, $isAdmin, $cursor, $perPage);
        $rows = $page['rows'];
        // Fetch badges in one batched query instead of N separate queries (like bootstrap)
        $ids      = array_map(fn($r) => (int) $r['id'], $rows);
        $badgeMap = $nm->getBadgesForIds($ids);
        $result   = [];
        foreach ($rows as $row) {
            $badges   = $badgeMap[(int) $row['id']] ?? [];
            $result[] = NotificationModel::toFrontend($row, $badges);
        }

        $nextCursor = null;
        if ($page['has_more'] && !empty($rows)) {
            $last       = end($rows);
            $nextCursor = Cursor::encode($last['created_at'], (int) $last['id']);
        }

        echo json_encode([
            'ok'            => true,
            'notifications' => $result,
            'has_more'      => $page['has_more'],
            'next_cursor'   => $nextCursor,
        ], JSON_UNESCAPED_UNICODE);
    }

    // ── unread_count: count of unread notifications — with ETag/304 support ──
    // (like notifications() above: since this endpoint is polled every ~25 seconds,
    // the count number + a hash of identity fields is enough as an ETag)
    //
    // For a logged-in user, identity fields (me) are also carried in this same response so
    // the poll cycle makes only one request instead of two (unread_count + me) —
    // this halves the request rate for logged-in users under high traffic.
    public function unreadCount(): void
    {
        if (!UserSession::check()) {
            // Not an error: the bell panel polls this endpoint to detect a session that
            // expired mid-use and reloads the page (which then redirects to /login) —
            // see NotifPanel._poll() in assets/js/script.js.
            header('Cache-Control: no-store');
            echo json_encode(['ok' => true, 'count' => 0, 'logged_in' => false], JSON_UNESCAPED_UNICODE);
            return;
        }

        $nm    = new NotificationModel();
        $count = $nm->unreadCount(UserSession::id(), UserSession::isAdmin());
        $tag   = 'notif-count-u' . UserSession::id();
        $me    = [
            'display_name' => $_SESSION['display_name'] ?? $_SESSION['username'] ?? '',
            'first_name'   => $_SESSION['first_name'] ?? '',
            'last_name'    => $_SESSION['last_name'] ?? '',
            'username'     => $_SESSION['username'] ?? '',
            'phone'        => $_SESSION['phone'] ?? '',
            'email'        => $_SESSION['email'] ?? '',
            'is_admin'     => (($_SESSION['role'] ?? 'user') === 'admin'),
            'can_view_profile'       => UserSession::canViewProfile(),
            'can_view_notifications' => UserSession::canViewNotifications(),
        ];

        // ETag also includes an identity hash so that editing name/email/role (without
        // count changing) doesn't get a 304 response and actually reaches the client
        $meHash     = '-' . substr(md5((string) json_encode($me)), 0, 12);
        $etag       = '"' . $tag . '-' . $count . $meHash . '"';
        $clientEtag = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';

        header('Cache-Control: private, max-age=0, must-revalidate');
        header('ETag: ' . $etag);

        if ($clientEtag === $etag) {
            http_response_code(304);
            exit;
        }

        echo json_encode(['ok' => true, 'count' => $count, 'logged_in' => true, 'me' => $me], JSON_UNESCAPED_UNICODE);
    }

    // ── mark_read: mark a notification as read ──
    public function markRead(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['ok' => false, 'msg' => 'Method Not Allowed']);
            return;
        }
        if (!UserSession::check()) {
            http_response_code(401);
            echo json_encode(['ok' => false, 'msg' => 'ابتدا وارد شوید']);
            return;
        }
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $nid  = (int) ($body['notification_id'] ?? 0);
        if ($nid <= 0) {
            echo json_encode(['ok' => false, 'msg' => 'شناسه اعلان نامعتبر است']);
            return;
        }
        $nm = new NotificationModel();
        if (!$nm->findById($nid)) {
            echo json_encode(['ok' => false, 'msg' => 'اعلان یافت نشد']);
            return;
        }
        $nm->markRead(UserSession::id(), $nid);
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    }

    // ── mark_all_read: mark all notifications as read ──
    public function markAllRead(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['ok' => false, 'msg' => 'Method Not Allowed']);
            return;
        }
        if (!UserSession::check()) {
            http_response_code(401);
            echo json_encode(['ok' => false, 'msg' => 'ابتدا وارد شوید']);
            return;
        }
        $nm = new NotificationModel();
        $nm->markAllRead(UserSession::id(), UserSession::isAdmin());
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    }
}
