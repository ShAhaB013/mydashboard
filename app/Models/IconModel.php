<?php
// ═══════════════════════════════════════════════════════════
// IconModel — CRUD operations on icons
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

    /** Gets all icons */
    public function all(): array
    {
        return $this->db->all();
    }

    /** Saves or edits an icon */
    public function save(string $key, string $svgPath): bool
    {
        $icons       = $this->all();
        $icons[$key] = $svgPath;
        $ok = $this->db->save($icons);
        // Assets are carried inside the guest bootstrap body — its cache must be invalidated
        MicroCache::forget('boot-guest');
        return $ok;
    }

    /** Deletes an icon */
    public function delete(string $key): bool
    {
        $icons = $this->all();
        unset($icons[$key]);
        $ok = $this->db->save($icons);
        MicroCache::forget('boot-guest');
        return $ok;
    }

    /** Checks whether an icon is protected */
    public function isProtected(string $key): bool
    {
        return in_array($key, $this->protected, true);
    }

    /** Checks whether an icon exists */
    public function exists(string $key): bool
    {
        return isset($this->all()[$key]);
    }
}
