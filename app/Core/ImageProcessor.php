<?php
declare(strict_types=1);

/**
 * ImageProcessor — optimizes images and generates thumbnails
 *
 * Features:
 *  - automatic EXIF orientation correction (mobile photos)
 *  - transparency preservation (PNG / WebP / GIF)
 *  - animated GIF passthrough (no loss of animation)
 *  - SVG and unknown formats passthrough
 *  - AVIF support (PHP 8.1+ with GD)
 *  - accurate memory check based on actual channels/bits
 *  - file-size guard: keeps the original if WebP ends up larger
 *  - no upscaling for the full version
 *
 * Requires: PHP >= 8.0 + GD with WebP support
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
     * Processes an uploaded image — creates a full version + thumbnail
     *
     * @return array{full:string|null, thumb:string|null}
     *   null = processing not possible; use the original file unchanged
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

        // Animated GIF -> passthrough (preserve the animation)
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

        $src = self::fixOrientation($src, $sourcePath, $type);

        // Actual dimensions after rotation
        $realW    = imagesx($src);
        $realH    = imagesy($src);
        $uuid     = self::uuid();
        $origSize = (int) @filesize($sourcePath);

        // ── compressed full version ─────────────────────────
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

        // file-size guard: if WebP ended up bigger than the original -> keep the original
        if ($origSize > 0 && is_file($fullDisk) && filesize($fullDisk) >= $origSize) {
            @unlink($fullDisk);
            imagedestroy($src);
            return ['full' => null, 'thumb' => null];
        }

        // ── thumbnail (square center crop) ──────────────────
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

    /** Deletes both image versions from disk */
    public static function deleteFiles(string $uploadDir, string $imagePath, ?string $thumbPath = null): void
    {
        $fullDisk = $uploadDir . '/' . basename($imagePath);
        if (is_file($fullDisk)) @unlink($fullDisk);

        $thumbBasename = $thumbPath ? basename($thumbPath) : basename($imagePath);
        $thumbDisk     = $uploadDir . '/' . self::THUMB_DIR . '/' . $thumbBasename;
        if (is_file($thumbDisk)) @unlink($thumbDisk);
    }

    /** Checks whether GD is available with WebP support */
    public static function isAvailable(): bool
    {
        return extension_loaded('gd') && function_exists('imagewebp');
    }

    // ── Private Helpers ──────────────────────────────────────

    /** Checks whether this file type can be processed by GD */
    private static function isProcessable(int $type): bool
    {
        $types = [
            IMAGETYPE_JPEG,
            IMAGETYPE_PNG,
            IMAGETYPE_GIF,
            IMAGETYPE_WEBP,
            IMAGETYPE_BMP,
        ];

        // AVIF — PHP 8.1+ with a sufficiently new GD
        if (defined('IMAGETYPE_AVIF') && function_exists('imagecreatefromavif')) {
            $types[] = IMAGETYPE_AVIF;
        }

        return in_array($type, $types, true);
    }

    /** Loads an image with GD based on its format type */
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
     * Corrects image orientation based on EXIF Orientation
     *
     * Mobile phones save photos with orientation metadata. Without this
     * correction, a portrait photo may display as landscape.
     *
     *   Orientation 3 = rotated 180 degrees
     *   Orientation 6 = 90 degrees CW  (Android portrait)
     *   Orientation 8 = 90 degrees CCW (Android upside down)
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
     * Detects animated GIFs
     *
     * An animated GIF has more than one Graphic Control Extension (00 21 F9 04).
     * GD only reads the first frame, so animated GIFs must be passed through.
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
     * Checks free memory before loading into GD
     *
     * Uses the image's actual channels and bits (more accurate than a fixed
     * estimate). The 2.5 factor = source buffer + destination + JPEG decode overhead
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

    /** Converts a memory_limit string (e.g. "256M") to bytes */
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
     * Computes fit dimensions while preserving aspect ratio — no upscaling
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
     * High-quality resize + preserves the alpha channel
     * Correctly handles transparent PNG/WebP
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
     * scale to fill + center crop for a square thumbnail
     *
     * Step 1: scale so both dimensions are filled (may upscale)
     * Step 2: crop from the center to dw×dh
     */
    private static function cropCenter(\GdImage $src, int $sw, int $sh, int $dw, int $dh): \GdImage
    {
        $ratio   = max($dw / max(1, $sw), $dh / max(1, $sh));
        $scaledW = max(1, (int) round($sw * $ratio));
        $scaledH = max(1, (int) round($sh * $ratio));

        // Step 1: scale
        $scaled = imagecreatetruecolor($scaledW, $scaledH);
        imagealphablending($scaled, false);
        imagesavealpha($scaled, true);
        $t = imagecolorallocatealpha($scaled, 0, 0, 0, 127);
        imagefilledrectangle($scaled, 0, 0, $scaledW - 1, $scaledH - 1, $t);
        imagecopyresampled($scaled, $src, 0, 0, 0, 0, $scaledW, $scaledH, $sw, $sh);

        // Step 2: center crop
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

    /** Creates the directory + a security .htaccess */
    private static function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        self::writeUploadHtaccess($dir);
    }

    /**
     * Canonical .htaccess content for upload directories — single source of
     * truth (used for both the main directory and thumbs). Defense in depth:
     * blocks execution of any PHP format + forces download of
     * executable/renderable formats (svg/xml/html/…) with a locked-down CSP,
     * so even if a file slips past the filters it still won't execute in the browser.
     */
    public static function uploadHtaccessBody(): string
    {
        return "Options -Indexes\n"
             . "<FilesMatch \"\\.ph(p[0-9]?|ar|tml)$\">\n    Require all denied\n</FilesMatch>\n"
             . "<IfModule mod_headers.c>\n"
             . "  <FilesMatch \"\\.(svg|svgz|xml|html?|xhtml|js|css)$\">\n"
             . "    Header set Content-Disposition \"attachment\"\n"
             . "    Header set Content-Security-Policy \"default-src 'none'; sandbox\"\n"
             . "    Header set X-Content-Type-Options \"nosniff\"\n"
             . "  </FilesMatch>\n"
             . "</IfModule>\n";
    }

    /**
     * Idempotent .htaccess write: only writes when the file is missing or its
     * content differs from the canonical version (upgrading older versions) —
     * so the hot upload path doesn't do an unnecessary disk write every time.
     */
    public static function writeUploadHtaccess(string $dir): bool
    {
        $path = $dir . '/.htaccess';
        $want = self::uploadHtaccessBody();
        if (is_file($path) && @file_get_contents($path) === $want) {
            return true;
        }
        return @file_put_contents($path, $want) !== false;
    }

    private static function uuid(): string
    {
        $d    = random_bytes(16);
        $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
        $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
    }
}