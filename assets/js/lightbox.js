/* ═══════════════════════════════════════════════════════════
   lightbox.js — full-screen image viewer (Image Lightbox)
   ───────────────────────────────────────────────────────────
   Standalone, no dependencies. Loaded in index.php and notifications.php.
   Usage:
     • Any <img> with class "js-lightbox" and a data-full attribute opens on click.
     • Or manually: Lightbox.open(fullUrl, altText)
   Features: smooth animation, close via ✕ / background click / Escape,
             zoom via click or scroll, and drag to pan while zoomed.
   ═══════════════════════════════════════════════════════════ */
(function () {
  'use strict';

  var overlay, imgEl, closeBtn, lastFocus = null;
  var scale = 1, panX = 0, panY = 0;
  var dragging = false, moved = false, startX = 0, startY = 0, downX = 0, downY = 0;
  var MIN = 1, MAX = 4, STEP = 0.35;

  // Build the DOM lazily (only once, on first use)
  function build() {
    if (overlay) return;
    overlay = document.createElement('div');
    overlay.className = 'lightbox';
    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-modal', 'true');
    overlay.setAttribute('aria-label', 'نمایش تمام‌صفحه تصویر');
    overlay.innerHTML =
      '<button type="button" class="lightbox-close" aria-label="بستن">' +
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true">' +
          '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>' +
        '</svg>' +
      '</button>' +
      '<img class="lightbox-img" src="" alt="" draggable="false">';
    document.body.appendChild(overlay);

    imgEl    = overlay.querySelector('.lightbox-img');
    closeBtn = overlay.querySelector('.lightbox-close');

    closeBtn.addEventListener('click', function (e) { e.stopPropagation(); close(); });
    overlay.addEventListener('click', function (e) { if (e.target === overlay) { e.stopPropagation(); close(); } });

    // Click on the image: if it wasn't dragged → toggle zoom
    imgEl.addEventListener('click', function (e) {
      e.stopPropagation();
      if (moved) { moved = false; return; }
      toggleZoom();
    });
    imgEl.addEventListener('wheel', onWheel, { passive: false });
    imgEl.addEventListener('pointerdown', onDown);
    window.addEventListener('pointermove', onMove);
    window.addEventListener('pointerup', onUp);
  }

  function applyTransform() {
    imgEl.style.transform = 'translate(' + panX + 'px,' + panY + 'px) scale(' + scale + ')';
    imgEl.style.cursor = scale > MIN ? (dragging ? 'grabbing' : 'grab') : 'zoom-in';
  }

  function resetTransform() { scale = 1; panX = 0; panY = 0; applyTransform(); }

  function toggleZoom() {
    if (scale > MIN) { resetTransform(); }
    else { scale = 2.2; applyTransform(); }
  }

  function onWheel(e) {
    e.preventDefault();
    scale = Math.min(MAX, Math.max(MIN, scale + (e.deltaY < 0 ? STEP : -STEP)));
    if (scale <= MIN) { scale = MIN; panX = 0; panY = 0; }
    applyTransform();
  }

  function onDown(e) {
    if (scale <= MIN) return;       // drag is only active while zoomed in
    dragging = true; moved = false;
    downX = e.clientX; downY = e.clientY;
    startX = e.clientX - panX; startY = e.clientY - panY;
    imgEl.classList.add('is-dragging');
    if (imgEl.setPointerCapture) { try { imgEl.setPointerCapture(e.pointerId); } catch (err) {} }
  }

  function onMove(e) {
    if (!dragging) return;
    panX = e.clientX - startX; panY = e.clientY - startY;
    if (Math.hypot(e.clientX - downX, e.clientY - downY) > 4) moved = true;
    applyTransform();
  }

  function onUp(e) {
    if (!dragging) return;
    dragging = false;
    imgEl.classList.remove('is-dragging');
    if (imgEl.releasePointerCapture) { try { imgEl.releasePointerCapture(e.pointerId); } catch (err) {} }
    applyTransform();
  }

  function open(src, alt) {
    if (!src) return;
    build();
    lastFocus = document.activeElement;
    scale = 1; panX = 0; panY = 0;
    imgEl.alt = alt || '';
    imgEl.src = src;
    imgEl.style.transform = 'scale(.92)';   // starting point for the entry animation
    imgEl.style.cursor = 'zoom-in';
    overlay.classList.add('open');
    requestAnimationFrame(function () {
      requestAnimationFrame(applyTransform);          // → scale(1) with transition
      if (closeBtn) closeBtn.focus({ preventScroll: true });
    });
  }

  function close() {
    if (!overlay || !overlay.classList.contains('open')) return;
    overlay.classList.remove('open');
    resetTransform();
    // Clear src after the animation ends (frees memory)
    setTimeout(function () {
      if (overlay && !overlay.classList.contains('open')) imgEl.src = '';
    }, 240);
    if (lastFocus && typeof lastFocus.focus === 'function') {
      lastFocus.focus({ preventScroll: true });
    }
    lastFocus = null;
  }

  // ── Open on click for marked images (event delegation) ──
  document.addEventListener('click', function (e) {
    var t = e.target;
    if (t && t.matches && t.matches('img.js-lightbox') && t.dataset.full) {
      e.stopPropagation();   // prevent other page handlers from reacting
      open(t.dataset.full, t.alt);
    }
  });

  // ── Escape in the capture phase: runs before underlying modal handlers ──
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && overlay && overlay.classList.contains('open')) {
      e.stopPropagation();
      close();
    }
  }, true);

  window.Lightbox = { open: open, close: close };
})();
