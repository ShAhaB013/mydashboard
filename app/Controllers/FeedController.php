<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════
// FeedController — اعلان‌های عمومی کاربر/مهمان (مسیر api.php)
//   notifications / unread_count / mark_read / mark_all_read
// (منطق عینا از api.php منتقل شده؛ caching/ETag/304 حفظ شده‌اند)
// ═══════════════════════════════════════════════════════════

class FeedController
{
    // ── notifications: اعلان‌های فعال قابل نمایش برای کاربر/مهمان جاری ──
    public function notifications(): void
    {
        $nm         = new NotificationModel();
        $isLoggedIn = UserSession::check();

        if ($isLoggedIn) {
            $rows = $nm->allActiveForUser(UserSession::id());
            // badgeها را به‌جای N کوئری مجزا، در یک کوئری دسته‌ای می‌گیریم (مثل bootstrap)
            $ids      = array_map(fn($r) => (int) $r['id'], $rows);
            $badgeMap = $nm->getBadgesForIds($ids);
            $result   = [];
            foreach ($rows as $row) {
                $badges   = $badgeMap[(int) $row['id']] ?? [];
                $result[] = NotificationModel::toFrontend($row, $badges);
            }
            $tag = 'notif-u' . UserSession::id();
        } else {
            $rows   = $nm->allForGuest();
            $result = [];
            foreach ($rows as $row) {
                $result[] = NotificationModel::toFrontend($row, []);
            }
            $tag = 'notif-guest';
        }

        // ETag/۳۰۴ برای هر دو حالت: poll و ناوبری اگر اعلانی عوض نشده باشد، ۳۰۴ می‌گیرند نه کل لیست
        $body       = json_encode(['ok' => true, 'notifications' => $result], JSON_UNESCAPED_UNICODE);
        $etag       = '"' . $tag . '-' . md5($body) . '"';
        $clientEtag = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';

        header($isLoggedIn
            ? 'Cache-Control: private, max-age=0, must-revalidate'
            : 'Cache-Control: public, max-age=60');
        header('ETag: ' . $etag);

        if ($clientEtag === $etag) {
            http_response_code(304);
            exit;
        }
        echo $body;
    }

    // ── unread_count: تعداد اعلان‌های خوانده‌نشده — فقط کاربران لاگین‌کرده ──
    public function unreadCount(): void
    {
        if (!UserSession::check()) {
            echo json_encode(['ok' => true, 'count' => 0], JSON_UNESCAPED_UNICODE);
            return;
        }
        $nm    = new NotificationModel();
        $count = $nm->unreadCount(UserSession::id());
        header('Cache-Control: private, no-store');
        echo json_encode(['ok' => true, 'count' => $count], JSON_UNESCAPED_UNICODE);
    }

    // ── mark_read: علامت‌گذاری یک اعلان به‌عنوان خوانده‌شده ──
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

    // ── mark_all_read: علامت‌گذاری همه اعلان‌ها به‌عنوان خوانده‌شده ──
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
