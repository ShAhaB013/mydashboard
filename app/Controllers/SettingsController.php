<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════
// SettingsController — saves email/SMTP settings + sends a test email
// ═══════════════════════════════════════════════════════════

class SettingsController
{
    private Request $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    /** Save settings */
    public function save(): void
    {
        $secure = strtolower(trim((string) $this->request->input('smtp_secure', 'tls')));
        if (!in_array($secure, ['tls', 'ssl', 'none'], true)) {
            $secure = 'tls';
        }

        $port = (int) $this->request->input('smtp_port', '587');
        if ($port < 1 || $port > 65535) {
            Response::error('پورت SMTP نامعتبر است (۱ تا ۶۵۵۳۵)', 'smtp_port');
            return;
        }

        $resend = (int) $this->request->input('resend_cooldown', '30');
        if ($resend < 10 || $resend > 600) {
            Response::error('فاصله ارسال مجدد باید بین ۱۰ تا ۶۰۰ ثانیه باشد', 'resend_cooldown');
            return;
        }

        $ttl = (int) $this->request->input('code_ttl', '600');
        if ($ttl < 60 || $ttl > 86400) {
            Response::error('مدت اعتبار کد باید بین ۶۰ تا ۸۶۴۰۰ ثانیه باشد', 'code_ttl');
            return;
        }

        $fromEmail = trim((string) $this->request->input('smtp_from_email'));
        if ($fromEmail !== '' && !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            Response::error('ایمیل فرستنده معتبر نیست', 'smtp_from_email');
            return;
        }

        $enabled = $this->request->input('smtp_enabled') ? '1' : '0';
        $host    = trim((string) $this->request->input('smtp_host'));
        if ($enabled === '1' && $host === '') {
            Response::error('برای فعال‌سازی SMTP باید آدرس سرور (host) را وارد کنید', 'smtp_host');
            return;
        }

        $kv = [
            'smtp_enabled'    => $enabled,
            'smtp_host'       => $host,
            'smtp_port'       => (string) $port,
            'smtp_secure'     => $secure,
            'smtp_user'       => trim((string) $this->request->input('smtp_user')),
            'smtp_from_email' => $fromEmail,
            'smtp_from_name'  => trim((string) $this->request->input('smtp_from_name')),
            'resend_cooldown' => (string) $resend,
            'code_ttl'        => (string) $ttl,
        ];

        // SMTP password is only updated if a value was entered
        // (leaving it blank = keep the previous password, so the admin doesn't have to retype it)
        $pass = (string) $this->request->input('smtp_pass');
        if ($pass !== '') {
            $kv['smtp_pass'] = $pass;
        }

        SettingsModel::setMany($kv);
        Response::ok();
    }

    /** Save just the debug-mode toggle (called from the log viewer page) */
    public function saveDebugMode(): void
    {
        $debugMode = $this->request->input('debug_mode') ? '1' : '0';
        SettingsModel::setMany(['debug_mode' => $debugMode]);
        Response::ok(['debug_mode' => $debugMode]);
    }

    /** Send a test email with the current settings */
    public function sendTest(): void
    {
        $to = trim((string) $this->request->input('test_email'));
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            Response::error('ایمیل مقصد آزمایش معتبر نیست', 'test_email');
            return;
        }
        if (!Mailer::isConfigured()) {
            Response::error('ابتدا SMTP را فعال و ذخیره کنید');
            return;
        }

        $res = Mailer::send(
            $to,
            'ایمیل آزمایشی — داشبورد ابزارها',
            "این یک ایمیل آزمایشی است.\nاگر آن را دریافت کردید، تنظیمات SMTP درست است."
        );

        if ($res['ok']) {
            Response::ok(['msg' => 'ایمیل آزمایشی ارسال شد']);
        } else {
            // Raw SMTP response can be long/multi-line; shortened for the Toast
            $err = trim(preg_replace('/\s+/', ' ', $res['error']));
            if (mb_strlen($err) > 160) {
                $err = mb_substr($err, 0, 160) . '…';
            }
            Response::error('ارسال ناموفق بود: ' . $err);
        }
    }
}
