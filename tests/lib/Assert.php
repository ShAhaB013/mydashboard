<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════
// Assert — assertion helper + global pass/fail counter for the whole test run
// ═══════════════════════════════════════════════════════════

class Assert
{
    public static int $pass = 0;
    public static int $fail = 0;
    public static int $warn = 0;
    /** @var array<int,array{group:string,name:string,message:string}> */
    public static array $failures = [];
    /** @var array<int,array{group:string,name:string,message:string}> */
    public static array $warnings = [];

    public static string $currentGroup = '';
    public static string $currentTest  = '';

    public static function group(string $name): void
    {
        self::$currentGroup = $name;
    }

    private static function record(bool $ok, string $message, ?array $context = null): void
    {
        if ($ok) {
            self::$pass++;
            return;
        }
        self::$fail++;
        $ctx = $context ? (' | ' . json_encode($context, JSON_UNESCAPED_UNICODE)) : '';
        self::$failures[] = [
            'group'   => self::$currentGroup,
            'name'    => self::$currentTest,
            'message' => $message . $ctx,
        ];
        fwrite(STDOUT, "  [FAIL] " . self::$currentTest . " — " . $message . $ctx . "\n");
    }

    public static function warn(string $message, ?array $context = null): void
    {
        self::$warn++;
        $ctx = $context ? (' | ' . json_encode($context, JSON_UNESCAPED_UNICODE)) : '';
        self::$warnings[] = ['group' => self::$currentGroup, 'name' => self::$currentTest, 'message' => $message . $ctx];
        fwrite(STDOUT, "  [WARN] " . self::$currentTest . " — " . $message . $ctx . "\n");
    }

    public static function test(string $name, callable $fn): void
    {
        self::$currentTest = $name;
        try {
            $fn();
        } catch (\Throwable $e) {
            self::record(false, 'exception: ' . $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
        }
    }

    public static function true(bool $cond, string $message, ?array $context = null): void
    {
        self::record($cond, $message, $context);
    }

    public static function eq($expected, $actual, string $message): void
    {
        self::record($expected === $actual, $message, ['expected' => $expected, 'actual' => $actual]);
    }

    public static function statusEq(array $res, int $expected, string $message): void
    {
        self::record($res['status'] === $expected, $message, ['expected' => $expected, 'actual' => $res['status'], 'body' => mb_substr($res['body'] ?? '', 0, 300)]);
    }

    public static function statusIn(array $res, array $expected, string $message): void
    {
        self::record(in_array($res['status'], $expected, true), $message, ['expected_one_of' => $expected, 'actual' => $res['status']]);
    }

    public static function jsonOk(array $res, string $message): void
    {
        $ok = ($res['json']['ok'] ?? null) === true;
        self::record($ok, $message, ['status' => $res['status'], 'json' => $res['json']]);
    }

    public static function jsonFail(array $res, string $message): void
    {
        $ok = ($res['json']['ok'] ?? null) === false;
        self::record($ok, $message, ['status' => $res['status'], 'json' => $res['json']]);
    }

    public static function contains(string $haystack, string $needle, string $message): void
    {
        self::record(str_contains($haystack, $needle), $message, ['needle' => $needle]);
    }

    public static function notContains(string $haystack, string $needle, string $message): void
    {
        self::record(!str_contains($haystack, $needle), $message, ['needle' => $needle]);
    }

    public static function summary(): array
    {
        return ['pass' => self::$pass, 'fail' => self::$fail, 'warn' => self::$warn];
    }
}
