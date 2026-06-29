<?php
// ═══════════════════════════════════════════════════════════
// JsonStore — خواندن و نوشتن فایل‌های JSON (ذخیره‌ساز فایلی آیکون/انیمیشن)
// نام پیشین: Database (با DB — اتصال MySQL — اشتباه گرفته می‌شد)
// ═══════════════════════════════════════════════════════════

class JsonStore
{
    private string $filePath;

    public function __construct(string $filePath)
    {
        $this->filePath = $filePath;
    }

    /** خواندن همه رکوردها */
    public function all(): array
    {
        if (!file_exists($this->filePath)) {
            return [];
        }

        $data = json_decode(file_get_contents($this->filePath), true);
        return is_array($data) ? $data : [];
    }

    /** ذخیره همه رکوردها — atomic write + file lock */
    public function save(array $data): bool
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $tmp  = $this->filePath . '.tmp';

        // نوشتن روی فایل موقت با قفل انحصاری
        if (file_put_contents($tmp, $json, LOCK_EX) === false) {
            return false;
        }

        // جایگزینی atomic — اگر rename شکست بخوره فایل اصلی سالم می‌مونه
        return rename($tmp, $this->filePath);
    }
}
