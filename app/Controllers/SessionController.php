<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════
// SessionController — مدیریت نشست‌های همزمان کاربران (فقط ادمین).
// فهرست نشست‌های فعال و پایان‌دادن (terminate) به آن‌ها.
// ═══════════════════════════════════════════════════════════

class SessionController
{
    private Request $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    /** فهرست نشست‌های فعال (همه، یا فیلترشده با user_id) */
    public function list(): void
    {
        $uid  = $this->request->inputInt('user_id', 0);
        $rows = SessionModel::active($uid > 0 ? $uid : null);
        $cur  = session_id();

        $out = array_map(static function (array $r) use ($cur): array {
            return [
                'id'         => (string) $r['id'],
                'is_current' => hash_equals((string) $cur, (string) $r['id']),
                'user_id'    => $r['user_id'] !== null ? (int) $r['user_id'] : null,
                'name'       => $r['display_name'] ?: ($r['username'] ?: 'مهمان'),
                'is_admin'   => (($r['role'] ?? '') === 'admin'),
                'ip'         => (string) ($r['ip'] ?? ''),
                'agent'      => SessionModel::describeAgent((string) ($r['user_agent'] ?? '')),
                'last_seen'  => (int) $r['last_seen'],
                'expires_at' => (int) $r['expires_at'],
            ];
        }, $rows);

        Response::ok(['sessions' => $out, 'current_id' => $cur]);
    }

    /** پایان‌دادن به یک نشست مشخص */
    public function terminate(): void
    {
        $id = $this->request->input('session_id');
        if ($id === '') {
            Response::error('شناسه نشست نامعتبر است');
            return;
        }
        SessionModel::terminate($id);
        Response::ok(['msg' => 'نشست پایان یافت']);
    }

    /** پایان همه نشست‌های یک کاربر (خروج اجباری از همه دستگاه‌ها) */
    public function terminateUser(): void
    {
        $uid = $this->request->inputInt('user_id', 0);
        if ($uid <= 0) {
            Response::error('کاربر نامعتبر است');
            return;
        }
        // اگر هدف، خود ادمین جاری است، نشست فعلی را نگه دار تا از پنل بیرون نیفتد.
        $except = ($uid === UserSession::id()) ? session_id() : null;
        $n = SessionModel::terminateUser($uid, $except);
        Response::ok(['msg' => "{$n} نشست پایان یافت", 'count' => $n]);
    }

    /** پایان همه نشست‌های دیگر (به‌جز نشست جاری ادمین) */
    public function terminateOthers(): void
    {
        $n = SessionModel::terminateOthers(session_id());
        Response::ok(['msg' => "{$n} نشست دیگر پایان یافت", 'count' => $n]);
    }

    /** ذخیره مدت فعال‌بودن نشست کاربران (ساعت) — کنترل درون‌خطی پنل نشست‌ها */
    public function saveTtl(): void
    {
        $hours = $this->request->inputInt('session_ttl_hours', 0);
        if ($hours < 1 || $hours > 720) {
            Response::error('مدت فعال‌بودن نشست باید بین ۱ تا ۷۲۰ ساعت باشد');
            return;
        }
        SettingsModel::setMany(['session_ttl_hours' => (string) $hours]);
        Response::ok(['msg' => 'مدت فعال‌بودن نشست ذخیره شد', 'hours' => $hours]);
    }
}
