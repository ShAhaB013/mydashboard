<?php
/**
 * error_page.php — themed HTML error page (403 / 404 / 500 / 503).
 * Rendered by ErrorPage::render(); expects $code, $title, $desc, $digits,
 * $debugDetail in scope.
 *
 * Fully self-contained: no DB, no session, no other app class, and no
 * dependency on style.css / theme.js — everything it needs (CSS variables,
 * base/body styles, aurora background, @font-face) is inlined below. This
 * matters because ErrorDocument serves this page even when every other
 * request is failing (IP/geo block → assets themselves get 403; dead DB;
 * missing config). Fonts are the one exception: they cannot be inlined
 * (CSP font-src 'self' forbids data: URLs), so @font-face uses
 * font-display: swap and degrades silently to the system font stack.
 *
 * The variable values are a copy of the subset used here from
 * assets/css/style.css (:root / [data-theme="dark"]) — when the palette
 * changes there, update this copy too (style.css carries the same note).
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
  <style>
    /* ── Self-contained base (copied subset of style.css — keep in sync) ──
       Only the variables/rules this page actually uses. No external CSS/JS:
       under an IP/geo block or total outage those requests 403/fail and the
       ErrorDocument would answer them with text/html, which the browser
       rejects for stylesheets (strict MIME checking). */
    :root {
      --color-bg-card:        #ffffff;
      --color-border:         #e2e8f0;
      --color-text-primary:   #0f172a;
      --color-text-secondary: #64748b;
      --color-text-muted:     #94a3b8;
      --color-accent:         #3e7de7;
      --color-accent-bg:      rgba(62,125,231,0.08);
      --color-accent-border:  rgba(62,125,231,0.25);
      --color-shadow-card:    rgba(62,125,231,0.12);

      --radius-sm: 10px;
      --radius-lg: 10px;
      --transition:        0.22s cubic-bezier(0.4,0,0.2,1);
      --transition-bounce: 0.32s cubic-bezier(0.34,1.56,0.64,1);

      /* aurora */
      --aurora-bg:      #edf1fb;
      --aurora-blob-1:  rgba(62,125,231,0.22);
      --aurora-blob-2:  rgba(139,92,246,0.18);
      --aurora-blob-3:  rgba(14,164,114,0.14);
      --aurora-blob-4:  rgba(139,92,246,0.10);
      --aurora-center:  rgba(255,255,255,0.40);
      --noise-opacity:  0.032;
    }
    [data-theme="dark"] {
      --color-bg-card:        #161b22;
      --color-border:         #30363d;
      --color-text-primary:   #e6edf3;
      --color-text-secondary: #8b949e;
      --color-text-muted:     #484f58;
      --color-accent:         #58a6ff;
      --color-accent-bg:      rgba(88,166,255,0.10);
      --color-accent-border:  rgba(88,166,255,0.28);
      --color-shadow-card:    rgba(88,166,255,0.14);

      /* aurora dark */
      --aurora-bg:      #0d1117;
      --aurora-blob-1:  rgba(88,166,255,0.12);
      --aurora-blob-2:  rgba(167,139,250,0.10);
      --aurora-blob-3:  rgba(52,211,153,0.08);
      --aurora-blob-4:  rgba(167,139,250,0.06);
      --aurora-center:  rgba(255,255,255,0.03);
      --noise-opacity:  0.045;
    }

    @font-face {
      font-family: 'DashboardFont';
      src: url('/fonts/vazir-font/Vazir-Variable.woff2') format('woff2');
      font-weight: 100 900; font-style: normal; font-display: swap;
    }
    @font-face {
      font-family: 'HeadingFont';
      src: url('/fonts/IRANSans/IRANSansWeb_Bold.woff') format('woff');
      font-weight: 700; font-style: normal; font-display: swap;
    }

    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    html { text-size-adjust: 100%; -webkit-text-size-adjust: 100%; overflow-x: hidden; }
    body {
      font-family: 'DashboardFont', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
      color: var(--color-text-primary);
      line-height: 1.6;
      -webkit-font-smoothing: antialiased;
      -webkit-tap-highlight-color: transparent;
      background-color: var(--aurora-bg);
      overflow-x: clip;
      user-select: none; -webkit-user-select: none;
      display: flex; align-items: center; justify-content: center;
      min-height: 100vh; padding: 24px;
    }

    /* Aurora + noise layers — same fixed, composited layers as style.css
       (.err-page sits above them via z-index: 1) */
    body::before {
      content: '';
      position: fixed; inset: 0; z-index: 0;
      pointer-events: none;
      transform: translateZ(0);
      background-image:
        radial-gradient(ellipse 75% 60% at  8% 15%, var(--aurora-blob-1) 0%, transparent 65%),
        radial-gradient(ellipse 60% 65% at 92%  8%, var(--aurora-blob-2) 0%, transparent 60%),
        radial-gradient(ellipse 65% 55% at 88% 90%, var(--aurora-blob-3) 0%, transparent 58%),
        radial-gradient(ellipse 55% 60% at  4% 92%, var(--aurora-blob-4) 0%, transparent 55%),
        radial-gradient(ellipse 80% 50% at 50% 50%, var(--aurora-center) 0%, transparent 70%);
    }
    body::after {
      content: '';
      position: fixed; inset: 0; z-index: 0;
      pointer-events: none;
      transform: translateZ(0);
      opacity: var(--noise-opacity);
      background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='300' height='300'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.75' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='300' height='300' filter='url(%23n)'/%3E%3C/svg%3E");
      background-size: 180px 180px;
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

    /* Same pattern as the project's bordered buttons: card background +
       1.5px border + unified 10px radius, accent colors on hover */
    .err-home-btn {
      display: inline-flex; align-items: center; gap: 8px;
      height: 40px; padding: 0 20px;
      font-family: 'DashboardFont', sans-serif; font-size: 13.5px; font-weight: 600;
      color: var(--color-text-secondary); text-decoration: none;
      background: var(--color-bg-card);
      border: 1.5px solid var(--color-border);
      border-radius: var(--radius-lg);
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
      user-select: text; -webkit-user-select: text; /* debug text must be copyable despite body's user-select: none */
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
          <!-- dir=rtl on the labels: the cradle is dir=ltr (for digit order), which
               would misplace punctuation in the Persian text (e.g. "اوه!") -->
          <span class="err-label err-label--top" dir="rtl"><?= ErrorPage::esc($labels[$i][0] ?? '') ?></span>
          <span class="err-digit"><?= ErrorPage::esc($digits[$i] ?? '') ?></span>
          <span class="err-label err-label--bottom" dir="rtl"><?= ErrorPage::esc($labels[$i][1] ?? '') ?></span>
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
