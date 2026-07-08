<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════
// AuthController — احراز هویت (عمومی، بدون CSRF — مسیر api.php)
//   login / forgot_password / verify_reset_code / reset_password / change_password
// کاربران فقط توسط ادمین ساخته می‌شوند؛ ثبت‌نام عمومی وجود ندارد (ورود با
// نام‌کاربری). بازیابی رمز عبور self-service با کد OTP ایمیلی امکان‌پذیر است.
// ═══════════════════════════════════════════════════════════

class AuthController
{
    // هشِ ساختگیِ ثابت (bcrypt معتبر) برای یکنواخت‌سازیِ زمانِ password_verify
    // وقتی نام‌کاربری وجود ندارد. رمزِ واقعیِ متناظری ندارد.
    private const DUMMY_HASH = '$2y$12$1A99m6tDXImsUdH2uSUnEOaFiQv3EnhFEAlrx1FaqIe9XGMqDNnvK';

    // ── login ────────────────────────────────────────────────
    public function login(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['ok' => false, 'msg' => 'Method Not Allowed']);
            return;
        }

        // لایه‌ی اول: محدودیت مبتنی بر IP (حمله از یک منبع).
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

        // ورود فقط با نام‌کاربری
        $row = DB::run(
            'SELECT * FROM users WHERE username = :username AND is_active = 1',
            [':username' => $identity]
        )->fetch();

        // زمان‌ثابت: اگر کاربر یافت نشد، password_verify روی یک هشِ ساختگی اجرا می‌شود
        // تا اختلافِ زمانِ پاسخ، وجود/عدم‌وجودِ نام‌کاربری را لو ندهد (ضدِ enumeration).
        $hash    = ($row && isset($row['password_hash'])) ? $row['password_hash'] : self::DUMMY_HASH;
        $isValid = password_verify($password, $hash);

        if ($row && $isValid) {
            // ارتقای تدریجی هش‌های قدیمی به cost فعلی (۱۲) هنگام ورود موفق.
            // بدون این، کاربرانِ قدیمی cost=10 می‌مانند و اختلافِ زمانی با DUMMY_HASH
            // (cost=12) کانالِ enumeration باز می‌گذارد. اینجا زمانی که رمزِ خام در
            // دست است، هش با cost جدید بازنویسی می‌شود.
            if (password_needs_rehash($row['password_hash'], PASSWORD_BCRYPT, ['cost' => UserModel::BCRYPT_COST])) {
                (new UserModel())->changePassword((int) $row['id'], $password);
            }
            // پاکسازی سشن‌های قبلی همین کاربر از همین مرورگر (بدون قیدِ IP).
            // چرا بدون IP؟ روی موبایل/شبکه‌های پویا IP کاربر مکررا عوض می‌شود؛
            // اگر IP در کلید باشد، ورود مجددِ همان مرورگر با IP جدید، نشستِ قبلی
            // را پاک نمی‌کند و آن نشست تا پایان TTL به‌صورت «روح» زنده می‌ماند.
            // کلیدِ user_id + user_agent همان مرورگر را دقیق شناسایی می‌کند و
            // نشستِ قبلی‌اش را جایگزین می‌کند (به‌جای انباشتِ نشست‌های تکراری).
            $uid = (int) $row['id'];
            $ua  = mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
            try {
                DB::run(
                    'DELETE FROM sessions WHERE user_id = :uid AND user_agent = :ua',
                    [':uid' => $uid, ':ua' => $ua]
                );
            } catch (\Throwable $e) {}

            session_regenerate_id(true);
            $_SESSION['user_id']      = $uid;
            $_SESSION['username']     = $row['username'];
            $_SESSION['display_name'] = $row['display_name'];
            $_SESSION['phone']        = $row['phone'] ?? '';
            $_SESSION['email']        = $row['email'] ?? '';
            $_SESSION['role']         = ($row['role'] ?? 'user') === 'admin' ? 'admin' : 'user';
            $_SESSION['login_time']   = time();
            // توکن CSRF در همین سشن واحد ساخته می‌شود (پنل ادمین از همین می‌خواند)
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
    // ارسال کد OTP بازیابی به ایمیل یک کاربر فعال (پاسخ یکنواخت ضد افشای وجود ایمیل).
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

        // محدودیت سمت‌سرور (مقاوم در برابر ریلود/بازکردن دوباره صفحه) — مستقل از وجود
        // کاربر اعمال می‌شود تا هم بازکردن دوباره صفحه دور زده نشود و هم وجود/عدم ایمیل لو نرود.
        $retry = ResendThrottle::retryAfter('reset', $email, $base);
        if ($retry > 0) {
            $resp['retry_after'] = $retry;          // کلاینت شمارش معکوس را با همین مقدار نشان می‌دهد؛ ایمیلی ارسال نمی‌شود
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
                $resp['dev_code'] = $code; // فقط وقتی ارسال واقعی ناموفق بوده و محیط محلی است
            }
        }
        ResendThrottle::record('reset', $email); // برای همه ایمیل‌ها (یکنواخت، ضد افشای وجود کاربر)
        echo json_encode($resp, JSON_UNESCAPED_UNICODE);
    }

    // ── verify_reset_code ────────────────────────────────────
    // مرحله میانی فراموشی رمز: فقط درستی کد را می‌سنجد (بدون مصرف/پاک‌کردن کد).
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
        // کد درست است — مصرف نمی‌شود؛ کاربر به مرحله «رمز جدید» می‌رود.
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    }

    // ── reset_password ───────────────────────────────────────
    // تایید کد بازیابی + تنظیم رمز جدید + ورود خودکار (هم‌راستا با session-setting فعلی login())
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

        // موفق → تنظیم رمز جدید، پاک‌سازی کد، و ورود خودکار (عینِ بلوکِ session-setting در login())
        $userModel->changePassword((int) $user['id'], $password);
        $userModel->clearResetCode((int) $user['id']);

        $uid = (int) $user['id'];
        $ua  = mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
        try {
            DB::run(
                'DELETE FROM sessions WHERE user_id = :uid AND user_agent = :ua',
                [':uid' => $uid, ':ua' => $ua]
            );
        } catch (\Throwable $e) {}

        session_regenerate_id(true);
        $_SESSION['user_id']      = $uid;
        $_SESSION['username']     = $user['username'];
        $_SESSION['display_name'] = $user['display_name'];
        $_SESSION['phone']        = $user['phone'] ?? '';
        $_SESSION['email']        = $user['email'] ?? '';
        $_SESSION['role']         = ($user['role'] ?? 'user') === 'admin' ? 'admin' : 'user';
        $_SESSION['login_time']   = time();
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
