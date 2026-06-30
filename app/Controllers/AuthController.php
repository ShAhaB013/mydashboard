<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════
// AuthController — احراز هویت (عمومی، بدون CSRF — مسیر api.php)
//   login / change_password
// کاربران فقط توسط ادمین ساخته می‌شوند؛ ثبت‌نام عمومی و بازیابی رمز از طریق
// ایمیل حذف شده است (ورود فقط با نام‌کاربری).
// ═══════════════════════════════════════════════════════════

class AuthController
{
    // ── login ────────────────────────────────────────────────
    public function login(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['ok' => false, 'msg' => 'Method Not Allowed']);
            return;
        }

        $limiter = new RateLimiter();

        if ($limiter->isBanned()) {
            $mins = (int) ceil($limiter->secondsUntilUnblock() / 60);
            http_response_code(429);
            echo json_encode([
                'ok'  => false,
                'msg' => "تعداد تلاش‌های ناموفق زیاد است. لطفا {$mins} دقیقه دیگر امتحان کنید.",
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $body     = json_decode(file_get_contents('php://input'), true) ?? [];
        $identity = trim($body['username'] ?? '');
        $password = $body['password']      ?? '';

        if ($identity === '' || $password === '') {
            echo json_encode(['ok' => false, 'msg' => 'نام کاربری و رمز عبور الزامی است']);
            return;
        }

        // ورود فقط با نام‌کاربری
        $row = DB::run(
            'SELECT * FROM users WHERE username = :username AND is_active = 1',
            [':username' => $identity]
        )->fetch();

        if ($row && password_verify($password, $row['password_hash'])) {
            // پاکسازی سشن‌های قبلی همین کاربر از همین مرورگر/IP (جلوگیری از سشن تکراری)
            $uid = (int) $row['id'];
            $ip  = mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
            $ua  = mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
            try {
                DB::run(
                    'DELETE FROM sessions WHERE user_id = :uid AND ip = :ip AND user_agent = :ua',
                    [':uid' => $uid, ':ip' => $ip, ':ua' => $ua]
                );
            } catch (\Throwable $e) {}

            session_regenerate_id(true);
            $_SESSION['user_id']      = $uid;
            $_SESSION['username']     = $row['username'];
            $_SESSION['display_name'] = $row['display_name'];
            $_SESSION['phone']        = $row['phone'] ?? '';
            $_SESSION['role']         = ($row['role'] ?? 'user') === 'admin' ? 'admin' : 'user';
            // توکن CSRF در همین سشن واحد ساخته می‌شود (پنل ادمین از همین می‌خواند)
            if (empty($_SESSION['csrf_token'])) {
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            }
            $limiter->reset();

            echo json_encode([
                'ok'           => true,
                'display_name' => $row['display_name'] ?: $row['username'],
                'is_admin'     => $_SESSION['role'] === 'admin',
            ], JSON_UNESCAPED_UNICODE);
        } else {
            $limiter->recordFailure();
            echo json_encode(['ok' => false, 'msg' => 'نام کاربری یا رمز عبور اشتباه است']);
        }
    }

    // ── change_password ──────────────────────────────────────
    public function changePassword(): void
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

        $body        = json_decode(file_get_contents('php://input'), true) ?? [];
        $currentPass = $body['current_password'] ?? '';
        $newPass     = $body['new_password']     ?? '';
        $confirmPass = $body['confirm_password'] ?? '';

        if (empty($currentPass) || empty($newPass) || empty($confirmPass)) {
            echo json_encode(['ok' => false, 'msg' => 'همه فیلدها الزامی هستند']);
            return;
        }
        if ($newPass !== $confirmPass) {
            echo json_encode(['ok' => false, 'msg' => 'رمز عبور جدید و تکرار آن یکسان نیستند']);
            return;
        }
        if (!PasswordPolicy::isAcceptable($newPass)) {
            echo json_encode(['ok' => false, 'msg' => PasswordPolicy::errorMessage()], JSON_UNESCAPED_UNICODE);
            return;
        }
        if ($newPass === $currentPass) {
            echo json_encode(['ok' => false, 'msg' => 'رمز عبور جدید نباید با رمز فعلی یکسان باشد']);
            return;
        }

        $userId = UserSession::id();
        $row    = DB::run(
            'SELECT password_hash FROM users WHERE id = :id AND is_active = 1',
            [':id' => $userId]
        )->fetch();

        if (!$row || !password_verify($currentPass, $row['password_hash'])) {
            echo json_encode(['ok' => false, 'msg' => 'رمز عبور فعلی اشتباه است']);
            return;
        }

        $userModel = new UserModel();
        $userModel->changePassword($userId, $newPass);
        echo json_encode(['ok' => true, 'msg' => 'رمز عبور با موفقیت تغییر کرد'], JSON_UNESCAPED_UNICODE);
    }
}
