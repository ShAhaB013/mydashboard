<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════
// AuthController — authentication (public, no CSRF — api.php route)
//   login / forgot_password / verify_reset_code / reset_password / change_password
// Users are only created by an admin; there's no public sign-up (login is by
// username). Self-service password recovery via emailed OTP code is available.
// ═══════════════════════════════════════════════════════════

class AuthController
{
    // Fixed dummy hash (valid bcrypt) to equalize password_verify's timing
    // when the username doesn't exist. Has no corresponding real password.
    private const DUMMY_HASH = '$2y$12$1A99m6tDXImsUdH2uSUnEOaFiQv3EnhFEAlrx1FaqIe9XGMqDNnvK';

    // ── login ────────────────────────────────────────────────
    public function login(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['ok' => false, 'msg' => 'Method Not Allowed']);
            return;
        }

        // First layer: IP-based rate limiting (attack from a single source).
        $limiter = new RateLimiter('user');

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

        // Login by username only
        $row = DB::run(
            'SELECT * FROM users WHERE username = :username AND is_active = 1',
            [':username' => $identity]
        )->fetch();

        // Constant-time: if the user isn't found, password_verify still runs against a dummy hash
        // so the response-time difference doesn't leak whether the username exists (anti-enumeration).
        $hash    = ($row && isset($row['password_hash'])) ? $row['password_hash'] : self::DUMMY_HASH;
        $isValid = password_verify($password, $hash);

        if ($row && $isValid) {
            // Gradually upgrade old hashes to the current cost (12) on successful login.
            // Without this, old users would stay at cost=10 and the timing difference vs
            // DUMMY_HASH (cost=12) would leave an enumeration channel open. Here, while
            // we still have the raw password, the hash is rewritten with the new cost.
            if (password_needs_rehash($row['password_hash'], PASSWORD_BCRYPT, ['cost' => UserModel::BCRYPT_COST])) {
                (new UserModel())->changePassword((int) $row['id'], $password);
            }
            // Clean up this user's previous session from this same browser, identified by
            // the anonymous dash_device cookie (see bootstrap.php) — not by User-Agent or
            // IP. User-Agent alone is frequently IDENTICAL across different physical
            // machines with the same browser/OS/version (this matters a lot when several
            // people share one account), and IP changes constantly on mobile/dynamic
            // networks — either one as the key would either evict a stranger's session or
            // leave "ghost" sessions lingering until TTL. The device cookie is the only one
            // of the three that's actually unique per physical browser install.
            $uid      = (int) $row['id'];
            $deviceId = (string) ($_COOKIE['dash_device'] ?? '');
            if ($deviceId !== '') {
                try {
                    DB::run(
                        'DELETE FROM sessions WHERE user_id = :uid AND device_id = :did',
                        [':uid' => $uid, ':did' => $deviceId]
                    );
                } catch (\Throwable $e) {
                    // Best-effort cleanup — login still proceeds either way, but a real
                    // DB problem here shouldn't vanish without a trace.
                    Logger::warning('پاکسازی نشست‌های قبلی هنگام ورود ناموفق بود: ' . $e->getMessage());
                }
            }

            session_regenerate_id(true);
            $_SESSION['user_id']      = $uid;
            $_SESSION['username']     = $row['username'];
            $_SESSION['display_name'] = $row['display_name'];
            $_SESSION['phone']        = $row['phone'] ?? '';
            $_SESSION['email']        = $row['email'] ?? '';
            $_SESSION['role']         = ($row['role'] ?? 'user') === 'admin' ? 'admin' : 'user';
            $_SESSION['login_time']   = time();
            $_SESSION['hidden_menus'] = (new MenuAccessModel())->getHidden($uid);
            // CSRF token is generated in this single session (the admin panel reads it from here)
            UserSession::ensureCsrfToken();
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

    // ── forgot_password ──────────────────────────────────────
    // Send a recovery OTP code to an active user's email (uniform response, anti email-existence disclosure).
    public function forgotPassword(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['ok' => false, 'msg' => 'Method Not Allowed']);
            return;
        }
        $body  = json_decode(file_get_contents('php://input'), true) ?? [];
        $email = trim($body['email'] ?? '');
        $emailErr = $email === '' ? 'ایمیل معتبر نیست' : Validator::email($email);
        if ($emailErr !== '') {
            echo json_encode(['ok' => false, 'field' => 'email', 'msg' => $emailErr], JSON_UNESCAPED_UNICODE);
            return;
        }

        $base = SettingsModel::getInt('resend_cooldown', 10, 600, 30);
        $resp = [
            'ok'              => true,
            'msg'             => 'اگر این ایمیل ثبت شده باشد، کد بازیابی ارسال شد',
            'resend_cooldown' => $base,
        ];

        // Server-side throttle (resistant to reload/reopening the page) — applied independently
        // of the user's existence so it can't be bypassed by reopening the page, and so email
        // existence isn't leaked either.
        $retry = ResendThrottle::retryAfter('reset', $email, $base);
        if ($retry > 0) {
            $resp['retry_after'] = $retry;          // Client shows the countdown using this value; no email is sent
            echo json_encode($resp, JSON_UNESCAPED_UNICODE);
            return;
        }

        $userModel = new UserModel();
        $user      = $userModel->findActiveByEmail($email);
        if ($user) {
            $code     = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $codeHash = password_hash($code, PASSWORD_BCRYPT);
            $userModel->setResetCode((int) $user['id'], $codeHash, time() + SettingsModel::getInt('code_ttl', 60, 86400, 600));
            $mail = Mailer::sendCode($email, $code, 'reset');
            if (!$mail['ok'] && Mailer::devCodeAllowed()) {
                $resp['dev_code'] = $code; // only when the real send failed and it's a local environment
            }
        }
        ResendThrottle::record('reset', $email); // for every email (uniform, anti user-existence disclosure)
        echo json_encode($resp, JSON_UNESCAPED_UNICODE);
    }

    // ── verify_reset_code ────────────────────────────────────
    // Intermediate step of password recovery: only checks the code's validity (doesn't consume/clear it).
    public function verifyResetCode(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['ok' => false, 'msg' => 'Method Not Allowed']);
            return;
        }
        $body  = json_decode(file_get_contents('php://input'), true) ?? [];
        $email = trim($body['email'] ?? '');
        $code  = trim((string) ($body['code'] ?? ''));
        if ($email === '' || $code === '') {
            echo json_encode(['ok' => false, 'msg' => 'ایمیل و کد الزامی است'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $userModel = new UserModel();
        $user      = $userModel->findActiveByEmail($email);
        if (!$user || empty($user['reset_code_hash'])) {
            echo json_encode(['ok' => false, 'msg' => 'درخواست بازیابی برای این ایمیل یافت نشد'], JSON_UNESCAPED_UNICODE);
            return;
        }
        if (time() > (int) $user['reset_expires']) {
            echo json_encode(['ok' => false, 'msg' => 'کد منقضی شده است؛ کد جدید بگیرید', 'expired' => true], JSON_UNESCAPED_UNICODE);
            return;
        }
        if ((int) $user['reset_attempts'] >= 5) {
            echo json_encode(['ok' => false, 'msg' => 'تعداد تلاش‌های نادرست زیاد است؛ کد جدید بگیرید', 'expired' => true], JSON_UNESCAPED_UNICODE);
            return;
        }
        if (!password_verify($code, (string) $user['reset_code_hash'])) {
            $userModel->incrementResetAttempts((int) $user['id']);
            echo json_encode(['ok' => false, 'field' => 'code', 'msg' => 'کد بازیابی نادرست است'], JSON_UNESCAPED_UNICODE);
            return;
        }
        // Code is correct — it isn't consumed; the user moves to the "new password" step.
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    }

    // ── reset_password ───────────────────────────────────────
    // Verify recovery code + set new password + auto-login (aligned with login()'s current session-setting)
    public function resetPassword(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['ok' => false, 'msg' => 'Method Not Allowed']);
            return;
        }
        $body        = json_decode(file_get_contents('php://input'), true) ?? [];
        $email       = trim($body['email']            ?? '');
        $code        = trim((string) ($body['code']   ?? ''));
        $password    = $body['password']              ?? '';
        $confirmPass = $body['confirm_password']      ?? '';

        if ($email === '' || $code === '' || $password === '') {
            echo json_encode(['ok' => false, 'msg' => 'ایمیل، کد و رمز عبور الزامی است'], JSON_UNESCAPED_UNICODE);
            return;
        }
        if ($confirmPass !== '' && $password !== $confirmPass) {
            echo json_encode(['ok' => false, 'field' => 'password', 'msg' => 'رمز عبور و تکرار آن یکسان نیستند'], JSON_UNESCAPED_UNICODE);
            return;
        }
        if (!PasswordPolicy::isAcceptable($password)) {
            echo json_encode(['ok' => false, 'field' => 'password', 'msg' => PasswordPolicy::errorMessage()], JSON_UNESCAPED_UNICODE);
            return;
        }

        $userModel = new UserModel();
        $user      = $userModel->findActiveByEmail($email);
        if (!$user || empty($user['reset_code_hash'])) {
            echo json_encode(['ok' => false, 'msg' => 'درخواست بازیابی برای این ایمیل یافت نشد'], JSON_UNESCAPED_UNICODE);
            return;
        }
        if (time() > (int) $user['reset_expires']) {
            echo json_encode(['ok' => false, 'msg' => 'کد منقضی شده است؛ کد جدید بگیرید', 'expired' => true], JSON_UNESCAPED_UNICODE);
            return;
        }
        if ((int) $user['reset_attempts'] >= 5) {
            echo json_encode(['ok' => false, 'msg' => 'تعداد تلاش‌های نادرست زیاد است؛ کد جدید بگیرید', 'expired' => true], JSON_UNESCAPED_UNICODE);
            return;
        }
        if (!password_verify($code, (string) $user['reset_code_hash'])) {
            $userModel->incrementResetAttempts((int) $user['id']);
            echo json_encode(['ok' => false, 'field' => 'code', 'msg' => 'کد بازیابی نادرست است'], JSON_UNESCAPED_UNICODE);
            return;
        }

        // Success → set new password, clear the code, and auto-login (same as the session-setting block in login())
        $userModel->changePassword((int) $user['id'], $password);
        $userModel->clearResetCode((int) $user['id']);

        $uid = (int) $user['id'];
        try {
            // Unlike login() (which only replaces this browser's previous session),
            // a password reset means the old password may be compromised — revoke
            // ALL of the user's sessions on every device/browser.
            DB::run(
                'DELETE FROM sessions WHERE user_id = :uid',
                [':uid' => $uid]
            );
        } catch (\Throwable $e) {
            // Best-effort cleanup — password reset still proceeds either way, but a
            // real DB problem here shouldn't vanish without a trace.
            Logger::warning('پاکسازی نشست‌های قبلی هنگام بازیابی رمز عبور ناموفق بود: ' . $e->getMessage());
        }

        session_regenerate_id(true);
        $_SESSION['user_id']      = $uid;
        $_SESSION['username']     = $user['username'];
        $_SESSION['display_name'] = $user['display_name'];
        $_SESSION['phone']        = $user['phone'] ?? '';
        $_SESSION['email']        = $user['email'] ?? '';
        $_SESSION['role']         = ($user['role'] ?? 'user') === 'admin' ? 'admin' : 'user';
        $_SESSION['login_time']   = time();
        $_SESSION['hidden_menus'] = (new MenuAccessModel())->getHidden($uid);
        UserSession::ensureCsrfToken();

        echo json_encode([
            'ok'           => true,
            'msg'          => 'رمز عبور با موفقیت تغییر کرد',
            'display_name' => $user['display_name'] ?: $user['username'],
            'is_admin'     => $_SESSION['role'] === 'admin',
        ], JSON_UNESCAPED_UNICODE);
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

        if (empty($currentPass)) {
            echo json_encode(['ok' => false, 'field' => 'current_password', 'msg' => 'رمز عبور فعلی الزامی است'], JSON_UNESCAPED_UNICODE);
            return;
        }
        if (empty($newPass)) {
            echo json_encode(['ok' => false, 'field' => 'new_password', 'msg' => 'رمز عبور جدید الزامی است'], JSON_UNESCAPED_UNICODE);
            return;
        }
        if (empty($confirmPass)) {
            echo json_encode(['ok' => false, 'field' => 'confirm_password', 'msg' => 'تکرار رمز عبور الزامی است'], JSON_UNESCAPED_UNICODE);
            return;
        }
        if ($newPass !== $confirmPass) {
            echo json_encode(['ok' => false, 'field' => 'confirm_password', 'msg' => 'رمز عبور جدید و تکرار آن یکسان نیستند'], JSON_UNESCAPED_UNICODE);
            return;
        }
        if (!PasswordPolicy::isAcceptable($newPass)) {
            echo json_encode(['ok' => false, 'field' => 'new_password', 'msg' => PasswordPolicy::errorMessage()], JSON_UNESCAPED_UNICODE);
            return;
        }
        if ($newPass === $currentPass) {
            echo json_encode(['ok' => false, 'field' => 'new_password', 'msg' => 'رمز عبور جدید نباید با رمز فعلی یکسان باشد'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $userId = UserSession::id();
        $row    = DB::run(
            'SELECT password_hash FROM users WHERE id = :id AND is_active = 1',
            [':id' => $userId]
        )->fetch();

        if (!$row || !password_verify($currentPass, $row['password_hash'])) {
            echo json_encode(['ok' => false, 'field' => 'current_password', 'msg' => 'رمز عبور فعلی اشتباه است'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $userModel = new UserModel();
        $userModel->changePassword($userId, $newPass);
        echo json_encode(['ok' => true, 'msg' => 'رمز عبور با موفقیت تغییر کرد'], JSON_UNESCAPED_UNICODE);
    }

    // ── update_my_name ───────────────────────────────────────
    // The logged-in user edits their own first/last name
    // (username/phone/email/role cannot be changed via this route).
    public function updateMyName(): void
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

        $body      = json_decode(file_get_contents('php://input'), true) ?? [];
        $firstName = trim((string) ($body['first_name'] ?? ''));
        $lastName  = trim((string) ($body['last_name']  ?? ''));

        if ($firstName === '' || $lastName === '') {
            echo json_encode(['ok' => false, 'msg' => 'نام و نام خانوادگی الزامی است'], JSON_UNESCAPED_UNICODE);
            return;
        }
        if (($err = Validator::name($firstName, 'نام')) !== '') {
            echo json_encode(['ok' => false, 'field' => 'first_name', 'msg' => $err], JSON_UNESCAPED_UNICODE);
            return;
        }
        if (($err = Validator::name($lastName, 'نام خانوادگی')) !== '') {
            echo json_encode(['ok' => false, 'field' => 'last_name', 'msg' => $err], JSON_UNESCAPED_UNICODE);
            return;
        }

        $userModel = new UserModel();
        $userModel->updateOwnName(UserSession::id(), $firstName, $lastName);

        echo json_encode([
            'ok'           => true,
            'msg'          => 'نام و نام خانوادگی با موفقیت به‌روزرسانی شد',
            'display_name' => trim($firstName . ' ' . $lastName),
        ], JSON_UNESCAPED_UNICODE);
    }
}
