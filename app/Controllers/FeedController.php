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
    //
    // ETag ارزان‌قیمت: چون این endpoint هر ۲۵ ثانیه توسط پنل زنگوله poll می‌شود
    // (هم مهمان هم کاربر لاگین‌شده)، ابتدا فقط یک کوئری سبک (id/updated_at/is_read)
    // اجرا و از آن ETag ساخته می‌شود؛ کوئری کامل + badges + serialize فقط وقتی
    // اجرا می‌شود که این fingerprint با If-None-Match مطابقت نداشته باشد — یعنی
    // در حالت پرتکرارِ «چیزی عوض نشده»، هزینه‌ی سنگین اصلا پرداخت نمی‌شود.
    public function notifications(): void
    {
        $isLoggedIn = UserSession::check();

        if ($isLoggedIn) {
            $this->notificationsForUser(UserSession::id());
            return;
        }

        // مهمان: پاسخ بین همه بازدیدکنندگان یکسان است (read-state مهمان در
        // localStorage کلاینت اعمال می‌شود، نه سرور) — کل بدنه در میکروکش
        // ضد-stampede نگه داشته می‌شود تا N poll همزمان فقط ۱ بار کوئری بزند.
        // TTL کوتاه‌تر از بازه polling (~۲۵s) تا تاخیر دیده‌شدن اعلان جدید محسوس نشود.
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

    // فید کاربر لاگین‌شده — per-user است و وارد میکروکش مشترک نمی‌شود؛
    // همان الگوی «fingerprint ارزان → 304» هزینه مسیر پرتکرار را حذف می‌کند.
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

        // فقط این‌جا (fingerprint مغایر) کوئری کامل + badges + serialize اجرا می‌شود
        $rows = $nm->allActiveForUser($uid);
        // badgeها را به‌جای N کوئری مجزا، در یک کوئری دسته‌ای می‌گیریم (مثل bootstrap)
        $ids      = array_map(fn($r) => (int) $r['id'], $rows);
        $badgeMap = $nm->getBadgesForIds($ids);
        $result   = [];
        foreach ($rows as $row) {
            $badges   = $badgeMap[(int) $row['id']] ?? [];
            $result[] = NotificationModel::toFrontend($row, $badges);
        }

        echo json_encode(['ok' => true, 'notifications' => $result], JSON_UNESCAPED_UNICODE);
    }

    // ── unread_count: تعداد اعلان‌های خوانده‌نشده — با پشتیبانی ETag/304 ──
    // (مثل notifications() بالا: چون این endpoint هر ~۲۵ ثانیه poll می‌شود،
    // عدد count + هش فیلدهای هویتی به‌عنوان ETag کافی است)
    //
    // برای کاربر لاگین‌شده فیلدهای هویتی (me) هم در همین پاسخ حمل می‌شود تا
    // چرخه‌ی poll به‌جای دو درخواست (unread_count + me) فقط یک درخواست بزند —
    // در ترافیک بالا نرخ درخواست کاربران لاگین‌شده را نصف می‌کند.
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

        // ETag شامل هش هویت هم هست تا ویرایش نام/ایمیل/نقش (بدون تغییر count)
        // پاسخ 304 نگیرد و به کلاینت برسد
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
