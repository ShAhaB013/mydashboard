<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════
// Reporter — خلاصه‌سازی و نوشتن گزارش JSON اجرای تست
// ═══════════════════════════════════════════════════════════

class Reporter
{
    public static function printSummary(string $title): void
    {
        $s = Assert::summary();
        fwrite(STDOUT, "\n" . str_repeat('─', 60) . "\n");
        fwrite(STDOUT, "{$title}: PASS={$s['pass']}  FAIL={$s['fail']}  WARN={$s['warn']}\n");
        fwrite(STDOUT, str_repeat('─', 60) . "\n");
    }

    public static function writeReport(string $resultsDir): string
    {
        if (!is_dir($resultsDir)) mkdir($resultsDir, 0755, true);
        $file = $resultsDir . '/run-' . date('Ymd-His') . '.json';
        $data = [
            'summary'  => Assert::summary(),
            'failures' => Assert::$failures,
            'warnings' => Assert::$warnings,
            'ts'       => date('c'),
        ];
        file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        file_put_contents($resultsDir . '/last-run.txt', $file);
        return $file;
    }
}
