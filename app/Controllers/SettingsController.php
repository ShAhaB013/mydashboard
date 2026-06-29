<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════
// SettingsController — ذخیره تنظیمات ایمیل/SMTP + ارسال ایمیل آزمایشی
// ═══════════════════════════════════════════════════════════

class SettingsController
{
    private Request $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    /** ذخیره تنظیمات */
    public function save(): void
    {
        $secure = strtolower(trim((string) $this->request->input('smtp_secure', 'tls')));
        if (!in_array($secure, ['tls', 'ssl', 'none'], true)) {
            $secure = 'tls';
        }

        $port = (int) $this->request->input('smtp_port', '587');
        if ($port < 1 || $port > 65535) {
            Response::error('پورت SMTP نامعتبر است (۱ تا ۶۵۵۳۵)');
            return;
        }

        $resend = (int) $this->request->input('resend_cooldown', '30');
        if ($resend < 10 || $resend > 600) {
            Response::error('فاصله ارسال مجدد باید بین ۱۰ تا ۶۰۰ ثانیه باشد');
            return;
        }

        $ttl = (int) $this->request->input('code_ttl', '600');
        if ($ttl < 60 || $ttl > 86400) {
            Response::error('مدت اعتبار کد باید بین ۶۰ تا ۸۶۴۰۰ ثانیه باشد');
            return;
        }

        $fromEmail = trim((string) $this->request->input('smtp_from_email'));
        if ($fromEmail !== '' && !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            Response::error('ایمیل فرستنده معتبر نیست');
            return;
        }

        $enabled = $this->request->input('smtp_enabled') ? '1' : '0';
        $host    = trim((string) $this->request->input('smtp_host'));
        if ($enabled === '1' && $host === '') {
            Response::error('برای فعال‌سازی SMTP باید آدرس سرور (host) را وارد کنید');
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

        // رمز SMTP فقط وقتی به‌روزرسانی می‌شود که مقداری وارد شده باشد
        // (خالی‌گذاشتن = حفظ رمز قبلی؛ تا ادمین مجبور به تایپ مجدد نباشد)
        $pass = (string) $this->request->input('smtp_pass');
        if ($pass !== '') {
            $kv['smtp_pass'] = $pass;
        }

        SettingsModel::setMany($kv);
        Response::ok();
    }

    /** ارسال یک ایمیل آزمایشی با تنظیمات فعلی */
    public function sendTest(): void
    {
        $to = trim((string) $this->request->input('test_email'));
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            Response::error('ایمیل مقصد آزمایش معتبر نیست');
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
            Response::error('ارسال ناموفق بود: ' . $res['error']);
        }
    }
}
