<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════
// FeedController — general user/guest notifications (api.php route)
//   notifications / unread_count / mark_read / mark_all_read
// (logic moved verbatim from api.php; caching/ETag/304 preserved)
// ═══════════════════════════════════════════════════════════

class FeedController
{
    // ── notifications: active notifications visible to the current user/guest ──
    //
    // Cheap ETag: since this endpoint is polled every 25 seconds by the bell panel
    // (both guest and logged-in users), only a lightweight query (id/updated_at/is_read)
    // runs first and an ETag is built from it; the full query + badges + serialize only
    // runs when this fingerprint doesn't match If-None-Match — meaning in the frequent
    // "nothing changed" case, the heavy cost is never paid at all.
    public function notifications(): void
    {
        $isLoggedIn = UserSession::check();

        if ($isLoggedIn) {
            $this->notificationsForUser(UserSession::id());
            return;
        }

        // Guest: the response is identical across all visitors (the guest's read-state
        // is applied client-side in localStorage, not on the server) — the whole body is
        // kept in an anti-stampede micro-cache so N concurrent polls only query once.
        // TTL is shorter than the polling interval (~25s) so the delay in seeing new notifications isn't noticeable.
        $body = MicroCache::remember('notif-guest', 20, static function (): string {
            $nm     = new NotificationModel();
            $result = [];
            foreach ($nm->allForGuest() as $row) {
                $result[] = NotificationModel::toFrontend($row, []);
            }
            return (string) json_encode(['ok' => true, 'notifications' => $result], JSON_UNESCAPED_UNICODE);
        });

        $etag       = '"notif-guest-' . md5($body) . '"';
        $clientEtag = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';

        header('Cache-Control: public, max-age=60, stale-while-revalidate=30');
        header('ETag: ' . $etag);

        if ($clientEtag === $etag) {
            http_response_code(304);
            exit;
        }

        echo $body;
    }

    // Logged-in user's feed — it's per-user and doesn't enter the shared micro-cache;
    // the same "cheap fingerprint → 304" pattern removes the cost of the frequent path.
    private function notificationsForUser(int $uid): void
    {
        $nm          = new NotificationModel();
        $fp          = $nm->activeUserFingerprint($uid);
        $fingerprint = implode('|', array_map(
            static fn($r) => $r['id'] . ':' . $r['updated_at'] . ':' . $r['is_read'],
            $fp
        ));

        $etag       = '"notif-u' . $uid . '-' . md5($fingerprint) . '"';
        $clientEtag = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';

        header('Cache-Control: private, max-age=0, must-revalidate');
        header('ETag: ' . $etag);

        if ($clientEtag === $etag) {
            http_response_code(304);
            exit;
        }

        // Only here (fingerprint mismatch) does the full query + badges + serialize run
        $rows = $nm->allActiveForUser($uid);
        // Fetch badges in one batched query instead of N separate queries (like bootstrap)
        $ids      = array_map(fn($r) => (int) $r['id'], $rows);
        $badgeMap = $nm->getBadgesForIds($ids);
        $result   = [];
        foreach ($rows as $row) {
            $badges   = $badgeMap[(int) $row['id']] ?? [];
            $result[] = NotificationModel::toFrontend($row, $badges);
        }

        echo json_encode(['ok' => true, 'notifications' => $result], JSON_UNESCAPED_UNICODE);
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
        $isLoggedIn = UserSession::check();
        $count      = 0;
        $tag        = 'notif-count-guest';
        $me         = null;

        if ($isLoggedIn) {
            $nm    = new NotificationModel();
            $count = $nm->unreadCount(UserSession::id());
            $tag   = 'notif-count-u' . UserSession::id();
            $me    = [
                'display_name' => $_SESSION['display_name'] ?? $_SESSION['username'] ?? '',
                'first_name'   => $_SESSION['first_name'] ?? '',
                'last_name'    => $_SESSION['last_name'] ?? '',
                'username'     => $_SESSION['username'] ?? '',
                'phone'        => $_SESSION['phone'] ?? '',
                'email'        => $_SESSION['email'] ?? '',
                'is_admin'     => (($_SESSION['role'] ?? 'user') === 'admin'),
            ];
        }

        // ETag also includes an identity hash so that editing name/email/role (without
        // count changing) doesn't get a 304 response and actually reaches the client
        $meHash     = $me !== null ? '-' . substr(md5((string) json_encode($me)), 0, 12) : '';
        $etag       = '"' . $tag . '-' . $count . $meHash . '"';
        $clientEtag = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';

        header($isLoggedIn
            ? 'Cache-Control: private, max-age=0, must-revalidate'
            : 'Cache-Control: public, max-age=60');
        header('ETag: ' . $etag);

        if ($clientEtag === $etag) {
            http_response_code(304);
            exit;
        }

        $resp = ['ok' => true, 'count' => $count, 'logged_in' => $isLoggedIn];
        if ($me !== null) {
            $resp['me'] = $me;
        }
        echo json_encode($resp, JSON_UNESCAPED_UNICODE);
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
        $nm->markAllRead(UserSession::id());
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    }
}
