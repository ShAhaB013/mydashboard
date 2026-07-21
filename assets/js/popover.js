'use strict';

/* ═══════════════════════════════════════════════════════════
   Unified popover shape (bell + user-menu dropdowns)

   Builds ONE path — rounded panel body + pointer arrow — and applies it as
   clip-path on the dropdown, so the arrow is literally part of the panel's
   shape (no pseudo-element, no rotated square, nothing that can detach
   visually). The arrow x is computed live from the anchor button's position,
   so it always points at the button that opened the popover — including the
   mobile full-width layout — and follows it across resize/scroll.
   The single continuous shadow comes from filter: drop-shadow() on the
   .pop-shell wrapper (see style.css), which traces this clipped silhouette.
   ═══════════════════════════════════════════════════════════ */
const Popover = (() => {
  const ARROW_H    = 9;   // arrow height — matches the panels' padding-top in style.css
  const ARROW_HALF = 9;   // half of the arrow base width (18px total, 45° slopes)
  const RADIUS     = 10;  // panel corner radius — keep equal to --radius (10px)
  const TIP        = 2.2; // tip rounding
  const BASE       = 5;   // smoothing run where the arrow base meets the panel edge

  const supported =
    typeof CSS !== 'undefined' &&
    CSS.supports?.('clip-path', 'path("M0 0h1v1z")') &&
    'ResizeObserver' in window;

  /* One closed path: top edge with the arrow carved in, then around the
     rounded rect. Slope points are collinear (x = ax ± y for 45°), so the
     quadratic joins at the base and tip stay tangent — no visible kinks. */
  function shapePath(w, h, ax) {
    const r = RADIUS, ah = ARROW_H, half = ARROW_HALF;
    const minX = r + half + BASE, maxX = w - r - half - BASE;
    ax = Math.min(Math.max(ax, minX), Math.max(minX, maxX));
    return `path("${[
      `M ${r} ${ah}`,
      `L ${ax - half - BASE} ${ah}`,
      `Q ${ax - half} ${ah} ${ax - half + 3.2} ${ah - 3.2}`,
      `L ${ax - TIP} ${TIP}`,
      `Q ${ax} 0 ${ax + TIP} ${TIP}`,
      `L ${ax + half - 3.2} ${ah - 3.2}`,
      `Q ${ax + half} ${ah} ${ax + half + BASE} ${ah}`,
      `L ${w - r} ${ah}`,
      `A ${r} ${r} 0 0 1 ${w} ${ah + r}`,
      `L ${w} ${h - r}`,
      `A ${r} ${r} 0 0 1 ${w - r} ${h}`,
      `L ${r} ${h}`,
      `A ${r} ${r} 0 0 1 0 ${h - r}`,
      `L 0 ${ah + r}`,
      `A ${r} ${r} 0 0 1 ${r} ${ah}`,
      'Z',
    ].join(' ')}")`;
  }

  function refit(panel, btn) {
    const shell = panel.parentElement;
    const w = panel.offsetWidth, h = panel.offsetHeight;
    if (!w || !h) return;
    // The shell is never transformed (the open/close scale lives on the
    // panel), so its rect is a stable reference even mid-animation.
    const ax = btn.getBoundingClientRect().left + btn.offsetWidth / 2
             - shell.getBoundingClientRect().left;
    panel.style.clipPath = shapePath(w, h, ax);
    // Grow out of the arrow tip, like a native macOS popover
    panel.style.transformOrigin = `${Math.round(ax)}px 0`;
  }

  function bind(panelId, btnId) {
    const panel = document.getElementById(panelId);
    const btn   = document.getElementById(btnId);
    if (!panel || !btn) return;

    const update = () => refit(panel, btn);
    new ResizeObserver(update).observe(panel); // notif list heights arrive async
    window.addEventListener('resize', update);
    window.addEventListener('scroll', () => {
      // only matters while open (mobile fixed shell vs. in-flow anchor)
      if (panel.classList.contains('open')) update();
    }, { passive: true });
    update();
  }

  function init() {
    if (!supported) return; // panels degrade to plain rounded cards
    bind('notifDropdown', 'notifBellBtn');
    bind('userMenuDropdown', 'userMenuBtn');
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  return { refit };
})();
