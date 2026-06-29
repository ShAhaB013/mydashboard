<?php
// ═══════════════════════════════════════════════════════════
// IconModel — عملیات CRUD روی آیکون‌ها
// ═══════════════════════════════════════════════════════════

class IconModel
{
    private JsonStore $db;
    private array     $protected;

    public function __construct(JsonStore $db, array $protectedKeys = ['star'])
    {
        $this->db        = $db;
        $this->protected = $protectedKeys;
    }

    /** دریافت همه آیکون‌ها */
    public function all(): array
    {
        return $this->db->all();
    }

    /** ذخیره یا ویرایش آیکون */
    public function save(string $key, string $svgPath): bool
    {
        $icons       = $this->all();
        $icons[$key] = $svgPath;
        return $this->db->save($icons);
    }

    /** حذف آیکون */
    public function delete(string $key): bool
    {
        $icons = $this->all();
        unset($icons[$key]);
        return $this->db->save($icons);
    }

    /** بررسی اینکه آیکون محافظت‌شده است */
    public function isProtected(string $key): bool
    {
        return in_array($key, $this->protected, true);
    }

    /** بررسی وجود آیکون */
    public function exists(string $key): bool
    {
        return isset($this->all()[$key]);
    }
}
