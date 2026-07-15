<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════
// SessionController — manages users' concurrent sessions (admin only).
// Lists active sessions and terminates them.
// ═══════════════════════════════════════════════════════════

class SessionController
{
    private Request $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    /** List of active sessions (all, or filtered by user_id), paginated (limit/offset) so
     *  a heavily-used install doesn't load thousands of rows — or silently truncate them —
     *  in one response. The panel infinite-scrolls further pages in via `offset`. */
    public function list(): void
    {
        $uid    = $this->request->inputInt('user_id', 0);
        $offset = max(0, $this->request->inputInt('offset', 0));
        $limit  = 50;
        $userId = $uid > 0 ? $uid : null;

        $rows  = SessionModel::active($userId, $limit, $offset);
        $total = SessionModel::activeCount($userId);
        $cur   = session_id();

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

        Response::ok([
            'sessions'   => $out,
            'current_id' => $cur,
            'total'      => $total,
            'offset'     => $offset,
            'has_more'   => ($offset + count($out)) < $total,
        ]);
    }

    /** End a specific session */
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

    /** End all sessions of a user (forced logout from all devices) */
    public function terminateUser(): void
    {
        $uid = $this->request->inputInt('user_id', 0);
        if ($uid <= 0) {
            Response::error('کاربر نامعتبر است');
            return;
        }
        // If the target is the current admin, keep the current session so they aren't kicked out of the panel.
        $except = ($uid === UserSession::id()) ? session_id() : null;
        $n = SessionModel::terminateUser($uid, $except);
        Response::ok(['msg' => "{$n} نشست پایان یافت", 'count' => $n]);
    }

    /** End all other sessions (except the current admin session) */
    public function terminateOthers(): void
    {
        $n = SessionModel::terminateOthers(session_id());
        Response::ok(['msg' => "{$n} نشست دیگر پایان یافت", 'count' => $n]);
    }

    /** Save users' session active-duration (hours) — inline control in the sessions panel */
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
