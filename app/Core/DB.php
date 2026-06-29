<?php
declare(strict_types=1);

class DB
{
    private static ?PDO $pdo = null;

    public static function connect(array $cfg): void
    {
        if (self::$pdo !== null) return;

        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=utf8mb4',
            $cfg['host'],
            $cfg['name']
        );

        self::$pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            // ↓ تثبیت charset و collation روی هر اتصال جدید
            // حتی اگر تنظیمات سرور cPanel عوض شود، این خط برنده است
            PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci',
        ]);
    }

    public static function get(): PDO
    {
        if (self::$pdo === null) {
            throw new RuntimeException('DB not initialized. Call DB::connect() first.');
        }
        return self::$pdo;
    }

    public static function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::get()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }
}