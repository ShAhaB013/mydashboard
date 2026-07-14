<?php
// ═══════════════════════════════════════════════════════════
// DecoModel — CRUD operations on card animations
// ═══════════════════════════════════════════════════════════

class DecoModel
{
    private JsonStore $db;
    private array     $protected;

    // Default SVG for the generic animation
    private const DEFAULT_GENERIC_SVG = '<svg class="card-deco" viewBox="0 0 120 60" aria-hidden="true" preserveAspectRatio="xMidYMid meet"><line x1="20" y1="15" x2="50" y2="30" stroke-dasharray="3 3" style="stroke:var(--card-color);opacity:.4;animation:pulseFade 3s ease-in-out infinite"/><line x1="50" y1="30" x2="80" y2="12" stroke-dasharray="3 3" style="stroke:var(--card-color);opacity:.4;animation:pulseFade 3s ease-in-out infinite;animation-delay:.5s"/><line x1="50" y1="30" x2="70" y2="48" stroke-dasharray="3 3" style="stroke:var(--card-color);opacity:.4;animation:pulseFade 3s ease-in-out infinite;animation-delay:1s"/><line x1="80" y1="12" x2="100" y2="35" stroke-dasharray="3 3" style="stroke:var(--card-color);opacity:.4;animation:pulseFade 3s ease-in-out infinite;animation-delay:1.5s"/><line x1="20" y1="15" x2="35" y2="45" stroke-dasharray="3 3" style="stroke:var(--card-color);opacity:.4;animation:pulseFade 3s ease-in-out infinite;animation-delay:.8s"/><line x1="35" y1="45" x2="70" y2="48" stroke-dasharray="3 3" style="stroke:var(--card-color);opacity:.4;animation:pulseFade 3s ease-in-out infinite;animation-delay:1.2s"/><circle cx="20" cy="15" r="3.5" style="fill:var(--card-color);animation:pulseFade 2s ease-in-out infinite;animation-delay:0s"/><circle cx="50" cy="30" r="4.5" style="fill:var(--card-color);animation:pulseFade 2s ease-in-out infinite;animation-delay:.3s"/><circle cx="80" cy="12" r="3" style="fill:var(--card-color);animation:pulseFade 2s ease-in-out infinite;animation-delay:.6s"/><circle cx="70" cy="48" r="3.5" style="fill:var(--card-color);animation:pulseFade 2s ease-in-out infinite;animation-delay:.9s"/><circle cx="100" cy="35" r="3" style="fill:var(--card-color);animation:pulseFade 2s ease-in-out infinite;animation-delay:1.2s"/><circle cx="35" cy="45" r="3" style="fill:var(--card-color);animation:pulseFade 2s ease-in-out infinite;animation-delay:.5s"/><circle cx="50" cy="30" r="10" style="fill:none;stroke:var(--card-color);stroke-width:1.5;animation:ringPulse 2.8s ease-in-out infinite;animation-delay:0s"/><circle cx="50" cy="30" r="18" style="fill:none;stroke:var(--card-color);stroke-width:1.5;animation:ringPulse 2.8s ease-in-out infinite;animation-delay:.5s"/></svg>';

    public function __construct(JsonStore $db, array $protectedKeys = ['generic'])
    {
        $this->db        = $db;
        $this->protected = $protectedKeys;
        $this->ensureGenericExists();
    }

    /** Gets all animations */
    public function all(): array
    {
        return $this->db->all();
    }

    /** Saves or edits an animation */
    public function save(string $key, string $svg): bool
    {
        $decos       = $this->all();
        $decos[$key] = $svg;
        return $this->db->save($decos);
    }

    /** Deletes an animation and reverts dependent tools back to generic */
    public function delete(string $key, ToolModel $toolModel): array
    {
        $decos = $this->all();
        unset($decos[$key]);
        $this->db->save($decos);

        // Revert tools that were using this animation back to generic
        $tools    = $toolModel->all();
        $affected = [];

        foreach ($tools as $i => $tool) {
            if (($tool['deco'] ?? '') === $key) {
                $tools[$i]['deco'] = 'generic';
                $affected[]        = $tool['title'] ?? '';
            }
        }

        if (!empty($affected)) {
            $toolModel->saveAll($tools);
        }

        return $affected;
    }

    /** Checks whether an animation is protected */
    public function isProtected(string $key): bool
    {
        return in_array($key, $this->protected, true);
    }

    /** Ensures the generic animation exists with correct content */
    private function ensureGenericExists(): void
    {
        $decos = $this->db->all();

        $needsUpdate = !isset($decos['generic'])
            || strpos($decos['generic'] ?? '', 'class="edge"') !== false
            || strpos($decos['generic'] ?? '', 'class="node"') !== false;

        if ($needsUpdate) {
            $decos['generic'] = self::DEFAULT_GENERIC_SVG;
            $this->db->save($decos);
        }
    }
}
