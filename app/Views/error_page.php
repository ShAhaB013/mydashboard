<?php
/**
 * error_page.php — themed HTML error page (403 / 404 / 500 / 503).
 * Rendered by ErrorPage::render(); expects $code, $title, $desc, $digits,
 * $debugDetail in scope. Loads only static assets (style.css, theme.js,
 * fonts) — no DB, no session, no other app class — so it still renders
 * correctly during a total outage (missing config, dead database, …).
 */
declare(strict_types=1);
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <meta name="robots" content="noindex, nofollow">
  <meta name="theme-color" content="#3e7de7">
  <meta name="color-scheme" content="light dark">
  <title><?= ErrorPage::esc($title) ?> — داشبورد ابزارهای کمکی</title>
  <link rel="preload" href="/fonts/vazir-font/Vazir-Variable.woff2" as="font" type="font/woff2" crossorigin="anonymous">
  <link rel="stylesheet" href="/assets/css/style.css">
  <?php // CSP nonce: required when the page is rendered via ErrorHandler
        // (bootstrap already sent an enforcing CSP header). On the standalone
        // error.php path there is no CSP header and no csp_nonce() — the
        // empty nonce attribute is harmless there.
        $cspNonce = function_exists('csp_nonce') ? csp_nonce() : ''; ?>
  <script nonce="<?= ErrorPage::esc($cspNonce) ?>">
    (function () {
      try {
        var saved = localStorage.getItem('theme');
        var prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
        if (saved === 'dark' || (!saved && prefersDark)) {
          document.documentElement.setAttribute('data-theme', 'dark');
        }
      } catch (e) {}
    })();
  </script>
  <script src="/assets/js/theme.js" defer></script>
  <style>
    body {
      display: flex; align-items: center; justify-content: center;
      min-height: 100vh; padding: 24px;
    }
    .err-page {
      position: relative; z-index: 1;
      display: flex; flex-direction: column; align-items: center;
      gap: 30px; max-width: 460px; text-align: center;
    }

    /* ── Pendulum cradle: three circles, one per digit ──
       Newton's-cradle motion: each circle hangs from an invisible pivot far
       above its center (transform-origin) and swings by ROTATION only — a
       true pendulum arc, never a translate/slide. Pivot ≈ 1.3 × diameter. */
    :root { --err-pivot: 192px; --err-swing: 35deg; --err-cycle: 3.6s; }
    .err-cradle { display: flex; align-items: center; gap: 3px; }
    .err-pendulum {
      transform-origin: 50% calc(-1 * var(--err-pivot));
      will-change: transform;
    }
    .err-circle {
      width: 148px; height: 148px; border-radius: 50%;
      display: flex; flex-direction: column; align-items: center; justify-content: center;
      position: relative;
      border: 1.5px solid var(--color-accent-border);
      background: var(--color-accent-bg);
      /* soft drop shadow + subtle inner glow */
      box-shadow: 0 22px 48px var(--color-shadow-card), inset 0 0 42px var(--color-accent-bg);
      will-change: transform;
    }
    .err-digit {
      font-family: 'HeadingFont', 'DashboardFont', sans-serif;
      font-size: 52px; font-weight: 700; line-height: 1; color: var(--color-accent);
    }
    /* Small labels above/below the digit (no letter-spacing: it would break
       the connected Persian script) */
    .err-label {
      position: absolute; white-space: nowrap;
      font-size: 10.5px; font-weight: 600; color: var(--color-text-secondary);
    }
    .err-label--top    { top: 17%; }
    .err-label--bottom { bottom: 17%; }
    @media (max-width: 560px) {
      :root { --err-pivot: 125px; }
      .err-cradle { gap: 2px; }
      .err-circle { width: 96px; height: 96px; }
      .err-digit { font-size: 32px; }
      .err-label { font-size: 8px; }
      .err-label--top { top: 14%; }
      .err-label--bottom { bottom: 14%; }
    }

    /* Slots (dir=ltr): a = LEFT ball, b = MIDDLE ball, c = RIGHT ball.
       Newton's-cradle cycle map (percent of --err-cycle):
         0%      left ball is struck — takes the energy
         0–14%   left swings OUT (decelerating)
         14–28%  left falls BACK (accelerating)
         28%     IMPACT #1 — left hits middle, energy → right
         28–31%  left overshoot + damped vibration, middle nudges
         31–45%  right swings OUT
         45–59%  right falls back
         59%     IMPACT #2 — right hits middle, energy → left
         59–62%  right overshoot + vibration, middle nudges
         62–100% rest / tiny settle; "energy returns to left" crosses
                 the 100%→0% boundary so the loop is seamless. */
    .err-pendulum--a { animation: err-swing-left  var(--err-cycle, 3.6s) infinite; }
    .err-pendulum--b { animation: err-swing-mid   var(--err-cycle, 3.6s) infinite; }
    .err-pendulum--c { animation: err-swing-right var(--err-cycle, 3.6s) infinite; }
    .err-circle--a   { animation: err-squash-left  var(--err-cycle, 3.6s) infinite; }
    .err-circle--b   { animation: err-squash-mid   var(--err-cycle, 3.6s) infinite; }
    .err-circle--c   { animation: err-squash-right var(--err-cycle, 3.6s) infinite; }

    @keyframes err-swing-left {
      0%      { transform: rotate(0deg); animation-timing-function: cubic-bezier(0.22, 0.6, 0.35, 1); }   /* launched */
      14%     { transform: rotate(var(--err-swing, 35deg)); animation-timing-function: cubic-bezier(0.65, 0, 0.78, 0.4); } /* apex */
      28%     { transform: rotate(0deg); animation-timing-function: ease-out; }  /* IMPACT #1 */
      29.5%   { transform: rotate(-1.6deg); }  /* slight overshoot into the middle */
      31%     { transform: rotate(0.6deg); }   /* damped rebound */
      33%     { transform: rotate(-0.25deg); } /* tiny vibration */
      35%     { transform: rotate(0deg); }
      59%     { transform: rotate(0deg); }     /* at rest while right swings */
      60.5%   { transform: rotate(0.5deg); }   /* receives impact #2 shudder */
      62%     { transform: rotate(-0.2deg); }
      64%     { transform: rotate(0deg); }
      100%    { transform: rotate(0deg); }     /* re-launched at 0% */
    }
    @keyframes err-swing-right {
      0%      { transform: rotate(0deg); }
      28%     { transform: rotate(0deg); animation-timing-function: cubic-bezier(0.22, 0.6, 0.35, 1); } /* struck by impact #1 */
      31%     { transform: rotate(-2deg); }    /* takes energy — kicks off */
      45%     { transform: rotate(calc(-1 * var(--err-swing, 35deg))); animation-timing-function: cubic-bezier(0.65, 0, 0.78, 0.4); } /* apex */
      59%     { transform: rotate(0deg); animation-timing-function: ease-out; } /* IMPACT #2 */
      60.5%   { transform: rotate(1.6deg); }   /* overshoot into middle */
      62%     { transform: rotate(-0.6deg); }  /* damped rebound */
      64%     { transform: rotate(0.25deg); }  /* tiny vibration */
      66%     { transform: rotate(0deg); }
      100%    { transform: rotate(0deg); }
    }
    @keyframes err-swing-mid {
      0%, 26% { transform: rotate(0deg); }
      28%     { transform: rotate(0deg); }
      29.5%   { transform: rotate(-1.4deg); }  /* nudge from the left impact */
      31.5%   { transform: rotate(0.5deg); }   /* damped */
      34%     { transform: rotate(-0.2deg); }
      36%     { transform: rotate(0deg); }
      59%     { transform: rotate(0deg); }
      60.5%   { transform: rotate(1.4deg); }   /* nudge from the right impact */
      62.5%   { transform: rotate(-0.5deg); }
      65%     { transform: rotate(0.2deg); }
      67%, 100% { transform: rotate(0deg); }
    }

    /* Squash & stretch on collision (scale only, no slide) */
    @keyframes err-squash-left {
      0%, 26%   { transform: scale(1, 1); }
      28%       { transform: scale(0.985, 1.012); } /* compression at impact #1 */
      30%       { transform: scale(1.006, 0.996); } /* rebound stretch */
      33%, 100% { transform: scale(1, 1); }
    }
    @keyframes err-squash-mid {
      0%, 26%   { transform: scale(1, 1); }
      28%       { transform: scale(0.988, 1.008); } /* hit from the left */
      30.5%     { transform: scale(1.005, 0.997); }
      34%, 57%  { transform: scale(1, 1); }
      59%       { transform: scale(0.988, 1.008); } /* hit from the right */
      61.5%     { transform: scale(1.005, 0.997); }
      65%, 100% { transform: scale(1, 1); }
    }
    @keyframes err-squash-right {
      0%, 57%   { transform: scale(1, 1); }
      59%       { transform: scale(0.985, 1.012); } /* compression at impact #2 */
      61%       { transform: scale(1.006, 0.996); }
      64%, 100% { transform: scale(1, 1); }
    }
    @media (prefers-reduced-motion: reduce) {
      .err-pendulum, .err-circle { animation: none !important; }
    }

    .err-title { font-family: 'HeadingFont', 'DashboardFont', sans-serif; font-size: 21px; font-weight: 700; color: var(--color-text-primary); }
    .err-desc  { font-size: 14px; color: var(--color-text-secondary); line-height: 1.8; margin-top: -14px; }

    /* Same pattern as the project's pill buttons (.auth-btn): card background +
       1.5px border + pill radius, accent colors on hover */
    .err-home-btn {
      display: inline-flex; align-items: center; gap: 8px;
      height: 40px; padding: 0 20px;
      font-family: 'DashboardFont', sans-serif; font-size: 13.5px; font-weight: 600;
      color: var(--color-text-secondary); text-decoration: none;
      background: var(--color-bg-card);
      border: 1.5px solid var(--color-border);
      border-radius: var(--radius-pill);
      cursor: pointer; white-space: nowrap;
      transition: background var(--transition), color var(--transition),
                  border-color var(--transition), transform var(--transition-bounce);
      position: relative; overflow: hidden; /* ripple containment */
    }
    .err-home-btn:hover {
      background: var(--color-accent-bg);
      border-color: var(--color-accent);
      color: var(--color-accent);
      transform: translateY(-1px);
    }
    .err-home-btn:focus-visible { outline: 2px solid var(--color-accent); outline-offset: 2px; }

    .err-debug {
      width: 100%; text-align: right; margin-top: -6px;
      font-size: 12.5px; color: var(--color-text-muted);
    }
    .err-debug summary { cursor: pointer; color: var(--color-text-secondary); }
    .err-debug pre {
      margin-top: 10px; padding: 12px 14px; max-height: 260px; overflow: auto;
      background: var(--color-bg-card); border: 1px solid var(--color-border);
      border-radius: var(--radius-sm); font-family: monospace; font-size: 12px;
      line-height: 1.6; white-space: pre-wrap; word-break: break-word; text-align: left; direction: ltr;
    }
  </style>
</head>
<body>
  <main class="err-page">
    <!-- dir=ltr: the page is RTL, but the digits of the status code must read
         left-to-right (otherwise 403 would render as "304") -->
    <div class="err-cradle" dir="ltr" aria-hidden="true">
      <?php foreach (['a', 'b', 'c'] as $i => $slot): ?>
      <div class="err-pendulum err-pendulum--<?= $slot ?>">
        <div class="err-circle err-circle--<?= $slot ?>">
          <span class="err-label err-label--top"><?= ErrorPage::esc($labels[$i][0] ?? '') ?></span>
          <span class="err-digit"><?= ErrorPage::esc($digits[$i] ?? '') ?></span>
          <span class="err-label err-label--bottom"><?= ErrorPage::esc($labels[$i][1] ?? '') ?></span>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <h1 class="err-title"><?= ErrorPage::esc($title) ?></h1>
    <p class="err-desc"><?= ErrorPage::esc($desc) ?></p>

    <a href="/" class="err-home-btn">
      <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M9 22V12h6v10"/>
      </svg>
      بازگشت به صفحه اصلی
    </a>

    <?php if (!empty($debugDetail)): ?>
    <details class="err-debug">
      <summary>جزئیات دقیق‌تر (فقط حالت دیباگ)</summary>
      <pre><?= ErrorPage::esc($debugDetail) ?></pre>
    </details>
    <?php endif; ?>
  </main>

  <script nonce="<?= ErrorPage::esc($cspNonce) ?>">
    // Keep the pendulum pivot proportional to the rendered circle size so the
    // arc stays natural at every viewport width, and clamp the swing
    // amplitude so the arc never leaves the viewport.
    (function () {
      var root = document.documentElement;
      function syncPivot() {
        var circle = document.querySelector('.err-circle');
        var cradle = document.querySelector('.err-cradle');
        if (!circle || !cradle) return;
        var d = circle.getBoundingClientRect().width;
        var pivot = d * 1.3;
        root.style.setProperty('--err-pivot', pivot.toFixed(0) + 'px');
        // Horizontal reach at apex ≈ sin(swing) × pivot must fit in the side margin.
        var margin = Math.max(0, (window.innerWidth - cradle.getBoundingClientRect().width) / 2);
        var maxRad = Math.asin(Math.min(1, Math.max(0.08, (margin - 12) / pivot)));
        var swing = Math.min(35, maxRad * 180 / Math.PI);
        root.style.setProperty('--err-swing', swing.toFixed(1) + 'deg');
      }
      window.addEventListener('resize', syncPivot, { passive: true });
      syncPivot();
    })();
  </script>
</body>
</html>
