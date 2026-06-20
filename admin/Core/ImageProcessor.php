<?php
declare(strict_types=1);

/**
 * ImageProcessor — بهینه‌سازی و تولید نسخه بند انگشتی تصاویر
 *
 * ویژگی‌ها:
 *  - تصحیح خودکار جهت EXIF (عکس‌های موبایل)
 *  - حفظ شفافیت (PNG / WebP / GIF)
 *  - Animated GIF passthrough (بدون از دست دادن انیمیشن)
 *  - SVG و فرمت‌های ناشناس passthrough
 *  - پشتیبانی AVIF (PHP 8.1+ با GD)
 *  - بررسی حافظه دقیق بر اساس channels/bits واقعی
 *  - file-size guard: اگر WebP از اصل بزرگتر شد، اصل نگه داشته می‌شود
 *  - upscale ممنوع برای نسخه کامل
 *
 * نیاز: PHP >= 8.0 + GD با WebP support
 */
class ImageProcessor
{
    public const THUMB_DIR = 'thumbs';

    private const FULL_MAX_W   = 1280;
    private const FULL_MAX_H   = 1280;
    private const FULL_QUALITY = 82;

    private const THUMB_W       = 200;
    private const THUMB_H       = 200;
    private const THUMB_QUALITY = 72;

    // ── Public API ───────────────────────────────────────────

    /**
     * پردازش تصویر آپلودشده — ایجاد نسخه کامل + بند انگشتی
     *
     * @return array{full:string|null, thumb:string|null}
     *   null = پردازش ممکن نیست؛ فایل اصلی بدون تغییر استفاده شود
     */
    public static function process(string $sourcePath, string $uploadDir, string $uploadUrl): array
    {
        if (!self::isAvailable()) {
            return ['full' => null, 'thumb' => null];
        }

        $info = @getimagesize($sourcePath);
        if (!$info) {
            return ['full' => null, 'thumb' => null];
        }

        [$origW, $origH, $type] = $info;

        if (!self::isProcessable($type)) {
            return ['full' => null, 'thumb' => null];
        }

        // GIF انیمیشنی → passthrough (حفظ انیمیشن)
        if ($type === IMAGETYPE_GIF && self::isAnimatedGif($sourcePath)) {
            return ['full' => null, 'thumb' => null];
        }

        @ini_set('memory_limit', '512M');
        @set_time_limit(300);

        if (!self::hasEnoughMemory($origW, $origH, $info)) {
            return ['full' => null, 'thumb' => null];
        }

        $src = self::loadSource($sourcePath, $type);
        if ($src === null) {
            return ['full' => null, 'thumb' => null];
        }

        // تصحیح جهت EXIF (عکس‌های موبایل)
        $src = self::fixOrientation($src, $sourcePath, $type);

        // ابعاد واقعی پس از چرخش
        $realW    = imagesx($src);
        $realH    = imagesy($src);
        $uuid     = self::uuid();
        $origSize = (int) @filesize($sourcePath);

        // ── نسخه کامل فشرده ──────────────────────────────────
        [$fw, $fh] = self::scaleFit($realW, $realH, self::FULL_MAX_W, self::FULL_MAX_H);
        $fullImg  = self::resample($src, $realW, $realH, $fw, $fh);
        $fullFile = $uuid . '.webp';
        $fullDisk = $uploadDir . '/' . $fullFile;

        if (!@imagewebp($fullImg, $fullDisk, self::FULL_QUALITY)) {
            imagedestroy($fullImg);
            imagedestroy($src);
            return ['full' => null, 'thumb' => null];
        }
        imagedestroy($fullImg);

        // file-size guard: اگر WebP از اصل بزرگتر بود → اصل را نگه دار
        if ($origSize > 0 && is_file($fullDisk) && filesize($fullDisk) >= $origSize) {
            @unlink($fullDisk);
            imagedestroy($src);
            return ['full' => null, 'thumb' => null];
        }

        // ── بند انگشتی (crop مرکزی مربع) ─────────────────────
        $thumbDir = $uploadDir . '/' . self::THUMB_DIR;
        self::ensureDir($thumbDir);

        $thumbImg  = self::cropCenter($src, $realW, $realH, self::THUMB_W, self::THUMB_H);
        $thumbDisk = $thumbDir . '/' . $uuid . '.webp';
        @imagewebp($thumbImg, $thumbDisk, self::THUMB_QUALITY);
        imagedestroy($thumbImg);
        imagedestroy($src);

        return [
            'full'  => $uploadUrl . '/' . $fullFile,
            'thumb' => $uploadUrl . '/' . self::THUMB_DIR . '/' . $uuid . '.webp',
        ];
    }

    /** حذف هر دو نسخه تصویر از دیسک */
    public static function deleteFiles(string $uploadDir, string $imagePath, ?string $thumbPath = null): void
    {
        $fullDisk = $uploadDir . '/' . basename($imagePath);
        if (is_file($fullDisk)) @unlink($fullDisk);

        $thumbBasename = $thumbPath ? basename($thumbPath) : basename($imagePath);
        $thumbDisk     = $uploadDir . '/' . self::THUMB_DIR . '/' . $thumbBasename;
        if (is_file($thumbDisk)) @unlink($thumbDisk);
    }

    /** بررسی در دسترس بودن GD با پشتیبانی WebP */
    public static function isAvailable(): bool
    {
        return extension_loaded('gd') && function_exists('imagewebp');
    }

    // ── Private Helpers ──────────────────────────────────────

    /** بررسی اینکه آیا این نوع فایل توسط GD قابل پردازش است */
    private static function isProcessable(int $type): bool
    {
        $types = [
            IMAGETYPE_JPEG,
            IMAGETYPE_PNG,
            IMAGETYPE_GIF,
            IMAGETYPE_WEBP,
            IMAGETYPE_BMP,
        ];

        // AVIF — PHP 8.1+ با GD نسخه کافی
        if (defined('IMAGETYPE_AVIF') && function_exists('imagecreatefromavif')) {
            $types[] = IMAGETYPE_AVIF;
        }

        return in_array($type, $types, true);
    }

    /** لود تصویر با GD بر اساس نوع فرمت */
    private static function loadSource(string $path, int $type): ?\GdImage
    {
        $img = match (true) {
            $type === IMAGETYPE_JPEG
                => @imagecreatefromjpeg($path),
            $type === IMAGETYPE_PNG
                => @imagecreatefrompng($path),
            $type === IMAGETYPE_GIF
                => @imagecreatefromgif($path),
            $type === IMAGETYPE_WEBP
                => @imagecreatefromwebp($path),
            $type === IMAGETYPE_BMP
                => @imagecreatefrombmp($path),
            defined('IMAGETYPE_AVIF') && $type === IMAGETYPE_AVIF && function_exists('imagecreatefromavif')
                => @imagecreatefromavif($path),
            default
                => false,
        };

        return ($img instanceof \GdImage) ? $img : null;
    }

    /**
     * تصحیح جهت تصویر بر اساس EXIF Orientation
     *
     * گوشی‌های موبایل عکس را با orientation metadata ذخیره می‌کنند.
     * بدون این اصلاح عکس پورتریت ممکن است landscape نمایش داده شود.
     *
     *   Orientation 3 = ۱۸۰ درجه چرخیده
     *   Orientation 6 = ۹۰ درجه CW  (اندروید پورتریت)
     *   Orientation 8 = ۹۰ درجه CCW (اندروید وارونه)
     */
    private static function fixOrientation(\GdImage $img, string $path, int $type): \GdImage
    {
        if ($type !== IMAGETYPE_JPEG || !function_exists('exif_read_data')) {
            return $img;
        }

        $exif = @exif_read_data($path);
        if (!$exif || empty($exif['Orientation'])) {
            return $img;
        }

        $rotated = match ((int) $exif['Orientation']) {
            3       => @imagerotate($img, 180, 0),
            6       => @imagerotate($img, -90, 0),
            8       => @imagerotate($img,  90, 0),
            default => null,
        };

        if ($rotated instanceof \GdImage) {
            imagedestroy($img);
            return $rotated;
        }

        return $img;
    }

    /**
     * تشخیص GIF انیمیشنی
     *
     * GIF انیمیشنی دارای بیش از یک Graphic Control Extension (00 21 F9 04) است.
     * GD فقط frame اول را می‌خواند، پس animated GIF باید passthrough شود.
     */
    private static function isAnimatedGif(string $path): bool
    {
        $fh = @fopen($path, 'rb');
        if (!$fh) {
            return false;
        }

        $count = 0;
        while (!feof($fh) && $count < 2) {
            $chunk = fread($fh, 65536);
            if ($chunk === false) break;
            $count += substr_count($chunk, "\x00\x21\xF9\x04");
        }
        fclose($fh);

        return $count > 1;
    }

    /**
     * بررسی حافظه آزاد قبل از لود GD
     *
     * از channels و bits واقعی تصویر استفاده می‌کند (دقیق‌تر از تخمین ثابت).
     * ضریب ۲.۵ = source buffer + destination + JPEG decode overhead
     */
    private static function hasEnoughMemory(int $w, int $h, array $info): bool
    {
        $channels      = isset($info['channels']) ? max(1, (int) $info['channels']) : 4;
        $bits          = isset($info['bits'])     ? max(1, (int) $info['bits'])     : 8;
        $bytesPerPixel = $channels * (int) ceil($bits / 8);
        $required      = (int) ($w * $h * $bytesPerPixel * 2.5);

        $limitStr = (string) ini_get('memory_limit');
        if ($limitStr === '-1') return true;

        $limit = self::parseMemoryLimit($limitStr);
        $free  = $limit - memory_get_usage(true);

        return $free >= $required;
    }

    /** تبدیل رشته memory_limit (مثلا "256M") به بایت */
    private static function parseMemoryLimit(string $str): int
    {
        $str = trim($str);
        if ($str === '' || $str === '-1') return PHP_INT_MAX;
        $unit = strtolower($str[-1]);
        $val  = (int) $str;
        return match ($unit) {
            'g'     => $val * 1_073_741_824,
            'm'     => $val * 1_048_576,
            'k'     => $val * 1_024,
            default => max(1, (int) $str),
        };
    }

    /**
     * محاسبه ابعاد fit با حفظ نسبت تصویر — بدون upscale
     */
    private static function scaleFit(int $w, int $h, int $maxW, int $maxH): array
    {
        if ($w <= $maxW && $h <= $maxH) return [$w, $h];
        $ratio = min($maxW / $w, $maxH / $h);
        return [
            max(1, (int) round($w * $ratio)),
            max(1, (int) round($h * $ratio)),
        ];
    }

    /**
     * resize با کیفیت بالا + حفظ کانال alpha
     * PNG/WebP با شفافیت را درست مدیریت می‌کند
     */
    private static function resample(\GdImage $src, int $sw, int $sh, int $dw, int $dh): \GdImage
    {
        $dst = imagecreatetruecolor($dw, $dh);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $t = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        imagefilledrectangle($dst, 0, 0, $dw - 1, $dh - 1, $t);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $dw, $dh, $sw, $sh);
        return $dst;
    }

    /**
     * scale to fill + crop مرکزی برای thumbnail مربع
     *
     * مرحله ۱: scale کوچک تا دو بعد پر شوند (ممکن است upscale کند)
     * مرحله ۲: crop از مرکز به اندازه dw×dh
     */
    private static function cropCenter(\GdImage $src, int $sw, int $sh, int $dw, int $dh): \GdImage
    {
        $ratio   = max($dw / max(1, $sw), $dh / max(1, $sh));
        $scaledW = max(1, (int) round($sw * $ratio));
        $scaledH = max(1, (int) round($sh * $ratio));

        // مرحله ۱: scale
        $scaled = imagecreatetruecolor($scaledW, $scaledH);
        imagealphablending($scaled, false);
        imagesavealpha($scaled, true);
        $t = imagecolorallocatealpha($scaled, 0, 0, 0, 127);
        imagefilledrectangle($scaled, 0, 0, $scaledW - 1, $scaledH - 1, $t);
        imagecopyresampled($scaled, $src, 0, 0, 0, 0, $scaledW, $scaledH, $sw, $sh);

        // مرحله ۲: crop مرکزی
        $offX = (int) round(($scaledW - $dw) / 2);
        $offY = (int) round(($scaledH - $dh) / 2);
        $dst  = imagecreatetruecolor($dw, $dh);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $t2 = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        imagefilledrectangle($dst, 0, 0, $dw - 1, $dh - 1, $t2);
        imagecopy($dst, $scaled, 0, 0, $offX, $offY, $dw, $dh);
        imagedestroy($scaled);

        return $dst;
    }

    /** ایجاد پوشه + htaccess امنیتی */
    private static function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $htaccess = $dir . '/.htaccess';
        if (!file_exists($htaccess)) {
            @file_put_contents(
                $htaccess,
                "Options -Indexes\n<FilesMatch \"\\.ph(p|ar|tml)$\">\n    Require all denied\n</FilesMatch>\n"
            );
        }
    }

    private static function uuid(): string
    {
        $d    = random_bytes(16);
        $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
        $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
    }
}