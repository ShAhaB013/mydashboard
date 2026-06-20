<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════
// AuthController — احراز هویت و مدیریت حساب (عمومی، بدون CSRF — مسیر api.php)
//   login / register / check_email / verify_email / resend_code /
//   forgot_password / verify_reset_code / reset_password / change_password
// (منطق عینا از api.php منتقل شده تا رفتار نشست/کد/کول‌داون تغییر نکند)
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
        $identity = trim($body['username'] ?? $body['email'] ?? '');
        $password = $body['password']      ?? '';

        if ($identity === '' || $password === '') {
            echo json_encode(['ok' => false, 'msg' => 'ایمیل و رمز عبور الزامی است']);
            return;
        }

        // ورود با ایمیل (کاربران جدید) یا نام کاربری (حساب‌های قدیمی/ادمین)
        // نکته: PDO اجازه استفاده دوباره یک placeholder را نمی‌دهد، پس دو پارامتر مجزا می‌دهیم.
        $row = DB::run(
            'SELECT * FROM users WHERE (email = :id_e OR username = :id_u) AND is_active = 1',
            [':id_e' => $identity, ':id_u' => $identity]
        )->fetch();

        if ($row && password_verify($password, $row['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user_id']      = (int) $row['id'];
            $_SESSION['username']     = $row['username'];
            $_SESSION['display_name'] = $row['display_name'];
            $_SESSION['email']        = $row['email'] ?? '';
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
            echo json_encode(['ok' => false, 'msg' => 'ایمیل یا رمز عبور اشتباه است']);
        }
    }

    // ── register ─────────────────────────────────────────────
    // ثبت‌نام عمومی: همیشه نقش 'user' (عادی). پس از ثبت موفق، ورود خودکار انجام می‌شود.
    public function register(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['ok' => false, 'msg' => 'Method Not Allowed']);
            return;
        }

        // محدودسازی سوءاستفاده: همان شمارنده IP که برای ورود استفاده می‌شود
        $limiter = new RateLimiter();
        if ($limiter->isBanned()) {
            $mins = (int) ceil($limiter->secondsUntilUnblock() / 60);
            http_response_code(429);
            echo json_encode([
                'ok'  => false,
                'msg' => "تعداد درخواست‌ها زیاد است. لطفا {$mins} دقیقه دیگر امتحان کنید.",
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $body        = json_decode(file_get_contents('php://input'), true) ?? [];
        $fullName    = trim($body['full_name']        ?? '');
        $email       = trim($body['email']            ?? '');
        $password    = $body['password']              ?? '';
        $confirmPass = $body['confirm_password']      ?? '';

        if ($fullName === '' || $email === '' || $password === '') {
            echo json_encode(['ok' => false, 'msg' => 'نام و نام خانوادگی، ایمیل و رمز عبور الزامی است'], JSON_UNESCAPED_UNICODE);
            return;
        }
        if ($nameErr = Validator::name($fullName, 'نام و نام خانوادگی')) {
            echo json_encode(['ok' => false, 'field' => 'full_name', 'msg' => $nameErr], JSON_UNESCAPED_UNICODE);
            return;
        }
        [$firstName, $lastName] = UserModel::splitName($fullName);
        $emailCheck = EmailValidator::validate($email);
        if (!$emailCheck['ok']) {
            echo json_encode(['ok' => false, 'field' => 'email', 'msg' => $emailCheck['msg']], JSON_UNESCAPED_UNICODE);
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
        if ($userModel->emailExistsActive($email)) {
            echo json_encode(['ok' => false, 'field' => 'email', 'msg' => 'این ایمیل قبلا ثبت شده است'], JSON_UNESCAPED_UNICODE);
            return;
        }

        // پاک‌سازی تلاش‌های نیمه‌کاره قبلی همین ایمیل، سپس ساخت رکورد در انتظار تایید + کد ۶ رقمی
        // نقش به‌صورت سخت‌گیرانه 'user' — هیچ راهی برای ادمین‌شدن از این مسیر نیست. username خودکار ساخته می‌شود.
        $userModel->deletePendingByEmail($email);
        $code     = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $codeHash = password_hash($code, PASSWORD_BCRYPT);
        $userModel->createPending($firstName, $lastName, $email, $password, $codeHash, time() + SettingsModel::getInt('code_ttl', 60, 86400, 600));

        $mail = Mailer::sendCode($email, $code, 'register');
        if (!$mail['ok'] && !Mailer::isLocal()) {
            echo json_encode(['ok' => false, 'msg' => 'ارسال ایمیل تایید ممکن نشد؛ کمی بعد دوباره تلاش کنید'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $resp = ['ok' => true, 'msg' => 'کد تایید به ایمیل ارسال شد', 'email' => $email, 'resend_cooldown' => SettingsModel::getInt('resend_cooldown', 10, 600, 30)];
        if (!$mail['ok'] && Mailer::isLocal()) {
            $resp['dev_code'] = $code; // فقط وقتی ارسال واقعی ناموفق بوده و محیط محلی است (نه وقتی SMTP ایمیل فرستاده)
        }
        // ثبت ارسال در شمارنده سمت‌سرور تا کول‌داون «ارسال مجدد» با ریلود دور زده نشود
        ResendThrottle::record('register', $email);
        echo json_encode($resp, JSON_UNESCAPED_UNICODE);
    }

    // ── check_email ──────────────────────────────────────────
    // اعتبارسنجی زنده ایمیل در مرحله ۱ ثبت‌نام: فرمت/MX/disposable + یکتایی.
    public function checkEmail(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['ok' => false, 'msg' => 'Method Not Allowed']);
            return;
        }
        $body  = json_decode(file_get_contents('php://input'), true) ?? [];
        $email = trim($body['email'] ?? '');

        $emailCheck = EmailValidator::validate($email);
        if (!$emailCheck['ok']) {
            echo json_encode(['ok' => false, 'field' => 'email', 'msg' => $emailCheck['msg']], JSON_UNESCAPED_UNICODE);
            return;
        }
        if ((new UserModel())->emailExistsActive($email)) {
            echo json_encode(['ok' => false, 'field' => 'email', 'msg' => 'این ایمیل قبلا ثبت شده است'], JSON_UNESCAPED_UNICODE);
            return;
        }
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    }

    // ── verify_email ─────────────────────────────────────────
    // تایید کد ۶ رقمی → فعال‌سازی حساب + ورود خودکار
    public function verifyEmail(): void
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
            echo json_encode(['ok' => false, 'msg' => 'ایمیل و کد تایید الزامی است'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $userModel = new UserModel();
        $pending   = $userModel->findPendingByEmail($email);
        if (!$pending) {
            echo json_encode(['ok' => false, 'msg' => 'درخواست تاییدی برای این ایمیل یافت نشد'], JSON_UNESCAPED_UNICODE);
            return;
        }
        if (time() > (int) $pending['verify_expires']) {
            echo json_encode(['ok' => false, 'msg' => 'کد منقضی شده است؛ کد جدید بگیرید', 'expired' => true], JSON_UNESCAPED_UNICODE);
            return;
        }
        if ((int) $pending['verify_attempts'] >= 5) {
            echo json_encode(['ok' => false, 'msg' => 'تعداد تلاش‌های نادرست زیاد است؛ کد جدید بگیرید', 'expired' => true], JSON_UNESCAPED_UNICODE);
            return;
        }
        if (!password_verify($code, (string) $pending['verify_code'])) {
            $userModel->incrementVerifyAttempts((int) $pending['id']);
            echo json_encode(['ok' => false, 'msg' => 'کد تایید نادرست است'], JSON_UNESCAPED_UNICODE);
            return;
        }

        // موفق → فعال‌سازی حساب و ورود خودکار
        $userModel->activateVerified((int) $pending['id']);
        session_regenerate_id(true);
        $_SESSION['user_id']      = (int) $pending['id'];
        $_SESSION['username']     = $pending['username'];
        $_SESSION['display_name'] = $pending['display_name'] ?: $pending['username'];
        $_SESSION['email']        = $pending['email'] ?? '';
        $_SESSION['role']         = 'user';
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        echo json_encode([
            'ok'           => true,
            'display_name' => $pending['display_name'] ?: $pending['username'],
            'is_admin'     => false,
        ], JSON_UNESCAPED_UNICODE);
    }

    // ── resend_code ──────────────────────────────────────────
    // ارسال مجدد کد تایید برای یک ثبت‌نام در انتظار
    public function resendCode(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['ok' => false, 'msg' => 'Method Not Allowed']);
            return;
        }
        $body  = json_decode(file_get_contents('php://input'), true) ?? [];
        $email = trim($body['email'] ?? '');
        if ($email === '') {
            echo json_encode(['ok' => false, 'msg' => 'ایمیل الزامی است'], JSON_UNESCAPED_UNICODE);
            return;
        }
        $userModel = new UserModel();
        $pending   = $userModel->findPendingByEmail($email);
        if (!$pending) {
            echo json_encode(['ok' => false, 'msg' => 'درخواست تاییدی برای این ایمیل یافت نشد'], JSON_UNESCAPED_UNICODE);
            return;
        }
        // محدودیت سمت‌سرور ارسال مجدد (مقاوم در برابر ریلود/بازکردن دوباره صفحه)
        $base  = SettingsModel::getInt('resend_cooldown', 10, 600, 30);
        $retry = ResendThrottle::retryAfter('register', $email, $base);
        if ($retry > 0) {
            echo json_encode(['ok' => false, 'msg' => 'برای ارسال مجدد کد کمی صبر کنید', 'retry_after' => $retry, 'resend_cooldown' => $base], JSON_UNESCAPED_UNICODE);
            return;
        }
        $code     = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $codeHash = password_hash($code, PASSWORD_BCRYPT);
        $userModel->setVerifyCode((int) $pending['id'], $codeHash, time() + SettingsModel::getInt('code_ttl', 60, 86400, 600));
        $mail = Mailer::sendCode($pending['email'], $code, 'register');
        if (!$mail['ok'] && !Mailer::isLocal()) {
            echo json_encode(['ok' => false, 'msg' => 'ارسال ایمیل ممکن نشد؛ کمی بعد دوباره تلاش کنید'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $resp = ['ok' => true, 'msg' => 'کد جدید ارسال شد', 'resend_cooldown' => $base];
        if (!$mail['ok'] && Mailer::isLocal()) {
            $resp['dev_code'] = $code;
        }
        ResendThrottle::record('register', $email);
        echo json_encode($resp, JSON_UNESCAPED_UNICODE);
    }

    // ── forgot_password ──────────────────────────────────────
    // ارسال کد بازیابی به ایمیل یک کاربر فعال (پاسخ یکنواخت ضد افشای وجود ایمیل).
    public function forgotPassword(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['ok' => false, 'msg' => 'Method Not Allowed']);
            return;
        }
        $body  = json_decode(file_get_contents('php://input'), true) ?? [];
        $email = trim($body['email'] ?? '');
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['ok' => false, 'field' => 'email', 'msg' => 'ایمیل معتبر نیست'], JSON_UNESCAPED_UNICODE);
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
            $userModel->setVerifyCode((int) $user['id'], $codeHash, time() + SettingsModel::getInt('code_ttl', 60, 86400, 600));
            $mail = Mailer::sendCode($email, $code, 'reset');
            if (!$mail['ok'] && Mailer::isLocal()) {
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
        if (!$user || empty($user['verify_code'])) {
            echo json_encode(['ok' => false, 'msg' => 'درخواست بازیابی برای این ایمیل یافت نشد'], JSON_UNESCAPED_UNICODE);
            return;
        }
        if (time() > (int) $user['verify_expires']) {
            echo json_encode(['ok' => false, 'msg' => 'کد منقضی شده است؛ کد جدید بگیرید', 'expired' => true], JSON_UNESCAPED_UNICODE);
            return;
        }
        if ((int) $user['verify_attempts'] >= 5) {
            echo json_encode(['ok' => false, 'msg' => 'تعداد تلاش‌های نادرست زیاد است؛ کد جدید بگیرید', 'expired' => true], JSON_UNESCAPED_UNICODE);
            return;
        }
        if (!password_verify($code, (string) $user['verify_code'])) {
            $userModel->incrementVerifyAttempts((int) $user['id']);
            echo json_encode(['ok' => false, 'field' => 'code', 'msg' => 'کد بازیابی نادرست است'], JSON_UNESCAPED_UNICODE);
            return;
        }
        // کد درست است — مصرف نمی‌شود؛ کاربر به مرحله «رمز جدید» می‌رود.
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    }

    // ── reset_password ───────────────────────────────────────
    // تایید کد بازیابی + تنظیم رمز جدید + ورود خودکار
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
        if (!$user || empty($user['verify_code'])) {
            echo json_encode(['ok' => false, 'msg' => 'درخواست بازیابی برای این ایمیل یافت نشد'], JSON_UNESCAPED_UNICODE);
            return;
        }
        if (time() > (int) $user['verify_expires']) {
            echo json_encode(['ok' => false, 'msg' => 'کد منقضی شده است؛ کد جدید بگیرید', 'expired' => true], JSON_UNESCAPED_UNICODE);
            return;
        }
        if ((int) $user['verify_attempts'] >= 5) {
            echo json_encode(['ok' => false, 'msg' => 'تعداد تلاش‌های نادرست زیاد است؛ کد جدید بگیرید', 'expired' => true], JSON_UNESCAPED_UNICODE);
            return;
        }
        if (!password_verify($code, (string) $user['verify_code'])) {
            $userModel->incrementVerifyAttempts((int) $user['id']);
            echo json_encode(['ok' => false, 'field' => 'code', 'msg' => 'کد بازیابی نادرست است'], JSON_UNESCAPED_UNICODE);
            return;
        }

        // موفق → تنظیم رمز جدید، پاک‌سازی کد، و ورود خودکار
        $userModel->changePassword((int) $user['id'], $password);
        $userModel->clearVerifyCode((int) $user['id']);
        session_regenerate_id(true);
        $_SESSION['user_id']      = (int) $user['id'];
        $_SESSION['username']     = $user['username'];
        $_SESSION['display_name'] = $user['display_name'] ?: $user['username'];
        $_SESSION['email']        = $user['email'] ?? '';
        $_SESSION['role']         = ($user['role'] ?? 'user') === 'admin' ? 'admin' : 'user';
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
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

    // ── request_email_change ─────────────────────────────────
    // کاربر واردشده درخواست تغییر ایمیل می‌دهد؛ کد ۶ رقمی به ایمیل جدید
    // ارسال می‌شود (اثبات مالکیت ایمیل جدید). کد + ایمیل جدید در نشست نگه‌داری می‌شود.
    public function requestEmailChange(): void
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

        $body  = json_decode(file_get_contents('php://input'), true) ?? [];
        $email = trim($body['email'] ?? '');

        $emailCheck = EmailValidator::validate($email);
        if (!$emailCheck['ok']) {
            echo json_encode(['ok' => false, 'field' => 'email', 'msg' => $emailCheck['msg']], JSON_UNESCAPED_UNICODE);
            return;
        }

        $userId  = UserSession::id();
        $current = strtolower(trim((string) ($_SESSION['email'] ?? '')));
        if (strtolower($email) === $current) {
            echo json_encode(['ok' => false, 'field' => 'email', 'msg' => 'ایمیل جدید با ایمیل فعلی یکسان است'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $userModel = new UserModel();
        if ($userModel->emailExists($email, $userId)) {
            echo json_encode(['ok' => false, 'field' => 'email', 'msg' => 'این ایمیل قبلا ثبت شده است'], JSON_UNESCAPED_UNICODE);
            return;
        }

        // محدودیت سمت‌سرور ارسال مجدد (مقاوم در برابر ریلود)
        $base  = SettingsModel::getInt('resend_cooldown', 10, 600, 30);
        $retry = ResendThrottle::retryAfter('email_change', $email, $base);
        if ($retry > 0) {
            echo json_encode(['ok' => false, 'msg' => 'برای ارسال مجدد کد کمی صبر کنید', 'retry_after' => $retry, 'resend_cooldown' => $base], JSON_UNESCAPED_UNICODE);
            return;
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $_SESSION['email_change'] = [
            'email'     => $email,
            'code_hash' => password_hash($code, PASSWORD_BCRYPT),
            'expires'   => time() + SettingsModel::getInt('code_ttl', 60, 86400, 600),
            'attempts'  => 0,
        ];

        $mail = Mailer::sendCode($email, $code, 'email_change');
        if (!$mail['ok'] && !Mailer::isLocal()) {
            echo json_encode(['ok' => false, 'msg' => 'ارسال ایمیل تایید ممکن نشد؛ کمی بعد دوباره تلاش کنید'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $resp = ['ok' => true, 'msg' => 'کد تایید به ایمیل جدید ارسال شد', 'email' => $email, 'resend_cooldown' => $base];
        if (!$mail['ok'] && Mailer::isLocal()) {
            $resp['dev_code'] = $code; // فقط محیط محلی وقتی SMTP ایمیل نفرستاده
        }
        ResendThrottle::record('email_change', $email);
        // کول‌داونِ قطعیِ سمت سرور تا ارسالِ مجددِ بعدی (کلاینت همین را نشان می‌دهد)
        $resp['retry_after'] = ResendThrottle::retryAfter('email_change', $email, $base);
        echo json_encode($resp, JSON_UNESCAPED_UNICODE);
    }

    // ── verify_email_change ──────────────────────────────────
    // تایید کد تغییر ایمیل و اعمال ایمیل جدید روی حساب.
    public function verifyEmailChange(): void
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
        $code = trim((string) ($body['code'] ?? ''));

        $pending = $_SESSION['email_change'] ?? null;
        if (!is_array($pending) || empty($pending['email'])) {
            echo json_encode(['ok' => false, 'msg' => 'درخواست تغییر ایمیلی یافت نشد؛ دوباره تلاش کنید'], JSON_UNESCAPED_UNICODE);
            return;
        }
        if ($code === '') {
            echo json_encode(['ok' => false, 'msg' => 'کد تایید الزامی است'], JSON_UNESCAPED_UNICODE);
            return;
        }
        if (time() > (int) $pending['expires']) {
            unset($_SESSION['email_change']);
            echo json_encode(['ok' => false, 'msg' => 'کد منقضی شده است؛ کد جدید بگیرید', 'expired' => true], JSON_UNESCAPED_UNICODE);
            return;
        }
        if ((int) $pending['attempts'] >= 5) {
            unset($_SESSION['email_change']);
            echo json_encode(['ok' => false, 'msg' => 'تعداد تلاش‌های نادرست زیاد است؛ کد جدید بگیرید', 'expired' => true], JSON_UNESCAPED_UNICODE);
            return;
        }
        if (!password_verify($code, (string) $pending['code_hash'])) {
            $_SESSION['email_change']['attempts'] = (int) $pending['attempts'] + 1;
            echo json_encode(['ok' => false, 'field' => 'code', 'msg' => 'کد تایید نادرست است'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $userId    = UserSession::id();
        $newEmail  = (string) $pending['email'];
        $userModel = new UserModel();
        // یکتایی را در لحظه اعمال دوباره بررسی کن (ممکن است در این فاصله گرفته شده باشد)
        if ($userModel->emailExists($newEmail, $userId)) {
            unset($_SESSION['email_change']);
            echo json_encode(['ok' => false, 'field' => 'email', 'msg' => 'این ایمیل قبلا ثبت شده است'], JSON_UNESCAPED_UNICODE);
            return;
        }
        $userModel->updateEmail($userId, $newEmail);
        $_SESSION['email'] = $newEmail;
        unset($_SESSION['email_change']);

        echo json_encode(['ok' => true, 'msg' => 'ایمیل با موفقیت تغییر کرد', 'email' => $newEmail], JSON_UNESCAPED_UNICODE);
    }

    // ── cancel_email_change ──────────────────────────────────
    // لغوِ درخواستِ در انتظارِ تغییرِ ایمیل (پاک‌کردنِ کدِ نشست). محدودیتِ
    // ارسالِ مجدد (ResendThrottle) عمداً پاک نمی‌شود تا با لغو/درخواستِ دوباره دور زده نشود.
    public function cancelEmailChange(): void
    {
        if (!UserSession::check()) {
            http_response_code(401);
            echo json_encode(['ok' => false, 'msg' => 'ابتدا وارد شوید']);
            return;
        }
        unset($_SESSION['email_change']);
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    }
}
