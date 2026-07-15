'use strict';

/* ═══════════════════════════════════════════════════════════
   Notification Detail Modal
   Shared between the header bell dropdown (index.php) and the
   notifications page (notifications.php) — one markup, one
   implementation. Callers pass a notification object with the
   API field names (image_path/thumbnail_path/target_all_users/badges/
   is_edited/is_expired/expires_at/created_at) and are responsible
   for marking it as read (after calling NotifDetail.open, so the
   "edited" pill still reflects the pre-read state).
   ═══════════════════════════════════════════════════════════ */

/* ── Sanitizes notification body HTML (second layer of defense) ──
   Only safe tags and attributes are allowed; the rest are stripped.
   The source text is also sanitized server-side; this is just a
   precautionary client-side layer. ── */
function sanitizeNotifHtml(html) {
  const ALLOWED_TAGS  = ['B','STRONG','I','EM','U','BR','P','DIV','SPAN','UL','OL','LI','A','FONT'];
  const ALLOWED_ATTRS = ['style','dir','href','target','rel','color','align'];
  const ALLOWED_CSS   = ['text-align','color','background-color','font-weight','font-style','text-decoration','direction'];
  const tpl = document.createElement('template');
  tpl.innerHTML = String(html ?? '');

  const walk = node => {
    [...node.childNodes].forEach(child => {
      if (child.nodeType === 1) { // element
        if (!ALLOWED_TAGS.includes(child.tagName)) {
          child.replaceWith(document.createTextNode(child.textContent || ''));
          return;
        }
        [...child.attributes].forEach(attr => {
          const name = attr.name.toLowerCase();
          if (!ALLOWED_ATTRS.includes(name)) { child.removeAttribute(attr.name); return; }
          if (name === 'style') {
            const safe = [];
            (child.getAttribute('style') || '').split(';').forEach(decl => {
              const idx = decl.indexOf(':');
              if (idx < 0) return;
              const k = decl.slice(0, idx).trim().toLowerCase();
              const v = decl.slice(idx + 1).trim();
              if (!k || !v) return;
              if (/url\(|expression|javascript:/i.test(v)) return;
              if (ALLOWED_CSS.includes(k)) safe.push(`${k}:${v}`);
            });
            if (safe.length) child.setAttribute('style', safe.join(';'));
            else child.removeAttribute('style');
          }
          if (name === 'href') {
            const v = (child.getAttribute('href') || '').trim();
            if (!/^(https?:|mailto:|\/)/i.test(v)) child.removeAttribute('href');
          }
        });
        if (child.tagName === 'A') { child.setAttribute('target','_blank'); child.setAttribute('rel','noopener noreferrer'); }
        walk(child);
      } else if (child.nodeType !== 3) {
        child.remove(); // comments and the like
      }
    });
  };
  walk(tpl.content);
  return tpl.innerHTML;
}

const NotifDetail = {
  open(n) {
    if (!n) return;

    // Captured before the caller marks it as read (post-open), so the
    // "edited" pill still shows for this view even once is_edited flips.
    const wasEdited = !!n.is_edited;

    document.getElementById('ndTitle').textContent = n.title || '';

    // Body (rich HTML — sanitized server-side, and again client-side)
    const textEl = document.getElementById('ndText');
    if (n.body) {
      textEl.innerHTML     = sanitizeNotifHtml(n.body);
      textEl.style.display = 'block';
    } else {
      textEl.style.display = 'none';
      textEl.innerHTML     = '';
    }

    // Image — progressive loading (thumbnail → full)
    const imgWrap = document.getElementById('ndImageWrap');
    const img     = document.getElementById('ndImage');
    if (n.image_path) {
      imgWrap.style.display = 'block';
      imgWrap.classList.add('img-loading');
      img.alt           = n.title || '';
      img.style.cssText = '';
      img.dataset.full  = n.image_path;   // basis for full-screen display (lightbox)

      if (n.thumbnail_path) {
        // Thumbnail available: show it immediately (blurred)
        img.src             = n.thumbnail_path;
        img.style.filter    = 'blur(10px)';
        img.style.transform = 'scale(1.04)';
      } else {
        // No thumbnail: img hidden — shimmer is shown instead
        img.src           = '';
        img.style.display = 'none';
      }

      // Load the full image in the background
      const loader   = new Image();
      loader.onload  = async () => {
        try { await loader.decode(); } catch {}
        img.style.display   = '';
        img.src             = n.image_path;
        img.style.filter    = '';
        img.style.transform = '';
        imgWrap.classList.remove('img-loading');
      };
      loader.onerror = () => {
        imgWrap.classList.remove('img-loading');
        img.style.display = '';
        if (!n.thumbnail_path) imgWrap.style.display = 'none';
      };
      loader.src = n.image_path;
    } else {
      imgWrap.style.display = 'none';
      img.src               = '';
      img.style.cssText     = '';
      delete img.dataset.full;
    }

    this._buildMeta(n, wasEdited);

    // "View all" link — only present in the bell dropdown's markup
    const allLink = document.getElementById('ndViewAllLink');
    if (allLink) allLink.style.display = 'inline-flex';

    // Show
    const overlay = document.getElementById('ndOverlay');
    const body    = overlay.querySelector('.nd-body');
    if (body) body.scrollTop = 0;
    overlay.classList.add('open');
    document.body.style.overflow = 'hidden';
    document.body.classList.add('notif-modal-open');
    // Focus the box itself (not the close button) so focus doesn't land on
    // the ✕, while still preserving Escape and accessibility.
    const box = overlay.querySelector('.nd-box');
    if (box) box.focus({ preventScroll: true });
  },

  close() {
    const overlay = document.getElementById('ndOverlay');
    overlay.classList.remove('open');
    document.body.style.overflow = '';
    document.body.classList.remove('notif-modal-open');
    // Reset progressive-loading state
    const img     = document.getElementById('ndImage');
    const imgWrap = document.getElementById('ndImageWrap');
    if (img)     { img.src = ''; img.style.cssText = ''; delete img.dataset.full; }
    if (imgWrap) imgWrap.classList.remove('img-loading');
  },

  _buildMeta(n, wasEdited) {
    const meta = document.getElementById('ndMeta');
    meta.innerHTML = '';

    const created = new Date(n.created_at);
    const dateRow = this._metaRow(
      '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
      created.toLocaleString('en-GB')
    );
    meta.appendChild(dateRow);

    if (n.expires_at) {
      const exp = new Date(n.expires_at * 1000);
      const expRow = this._metaRow(
        '<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>',
        (n.is_expired ? 'منقضی شد: ' : 'انقضا: ') + exp.toLocaleString('en-GB')
      );
      if (n.is_expired) expRow.classList.add('expired-row');
      meta.appendChild(expRow);
    }

    // pills (all-users + badges + edited/expired flags)
    const pills = [];
    if (n.target_all_users) pills.push({ text: 'عمومی', cls: 'npill-all' });
    (n.badges || []).forEach(b => pills.push({ text: b, cls: 'npill-badge' }));
    if (wasEdited)    pills.push({ text: 'ویرایش شده', cls: 'npill-edited' });
    if (n.is_expired) pills.push({ text: 'منقضی‌شده', cls: 'npill-expired' });

    if (pills.length) {
      const pillWrap = document.createElement('div');
      pillWrap.className = 'nd-pills';
      pills.forEach(p => {
        const sp = document.createElement('span');
        sp.className   = `npill ${p.cls}`;
        sp.textContent = p.text;
        pillWrap.appendChild(sp);
      });
      meta.appendChild(pillWrap);
    }
  },

  _metaRow(svgPaths, text) {
    const row = document.createElement('div');
    row.className = 'nd-meta-row';
    row.innerHTML = `
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
        ${svgPaths}
      </svg>
      <span>${this._esc(text)}</span>`;
    return row;
  },

  _esc(s) {
    return String(s ?? '')
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  },
};

// Escape-key handling is left to each page: index.php's global keydown handler
// prioritizes the detail modal over the notif dropdown/user menu (script.js),
// while notifications.php has nothing else to prioritize against (notifications.js).
document.getElementById('ndCloseBtn')?.addEventListener('click',    () => NotifDetail.close());
document.getElementById('ndCloseAction')?.addEventListener('click', () => NotifDetail.close());

document.getElementById('ndOverlay')?.addEventListener('click', e => {
  if (e.target === e.currentTarget) NotifDetail.close();
});
