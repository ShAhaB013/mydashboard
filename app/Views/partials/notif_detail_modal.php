<?php
/**
 * Notification detail modal — shared by the header bell dropdown (index.php)
 * and the notifications page (notifications.php). Content is filled in by
 * NotifDetail.open(n) in assets/js/notif-detail.js.
 *
 * Set $notifModalShowViewAll = false before including this partial to omit
 * the "view all notifications" footer link (e.g. on the notifications page
 * itself, where that link would just point back to the current page).
 */
$notifModalShowViewAll = $notifModalShowViewAll ?? true;
?>
  <div
    class="nd-overlay"
    id="ndOverlay"
    role="dialog"
    aria-modal="true"
    aria-labelledby="ndTitle"
  >
    <div class="nd-box" tabindex="-1" style="outline:none;">

      <div class="nd-head">
        <h2 class="nd-head-title" id="ndTitle"></h2>
        <button class="nd-close-btn" id="ndCloseBtn" aria-label="بستن">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
            <line x1="18" y1="6" x2="6" y2="18"/>
            <line x1="6" y1="6" x2="18" y2="18"/>
          </svg>
        </button>
      </div>

      <div class="nd-body">

        <!-- Image: loaded only on open() -->
        <div class="nd-image-wrap" id="ndImageWrap">
          <img id="ndImage" class="js-lightbox" src="" alt="" loading="lazy">
        </div>

        <div class="nd-content">
          <div class="nd-text" id="ndText"></div>

          <div class="nd-meta" id="ndMeta"></div>
        </div>

      </div>

      <div class="nd-foot">
        <button class="nd-close-action" id="ndCloseAction">بستن</button>
<?php if ($notifModalShowViewAll): ?>
        <a href="/notifications" class="nd-view-all-link" id="ndViewAllLink" style="display:none;">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
            <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
            <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
          </svg>
          مشاهده همه اعلان‌ها
        </a>
<?php endif; ?>
      </div>

    </div>
  </div>
