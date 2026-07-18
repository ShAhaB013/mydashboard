'use strict';

/* ═══════════════════════════════════════════════════════════
   Constants
   ═══════════════════════════════════════════════════════════ */
const FILTER_ALL      = 'all';
const DECO_FALLBACK   = 'generic';
const ICON_FALLBACK   = 'star';
const BADGE_FALLBACK  = 'ابزار';
const SEARCH_DEBOUNCE = 160;

const API_URL = 'api.php';

/* ── Show/hide password (login modal) ── */
function togglePass(inputId, btn) {
  const input = document.getElementById(inputId);
  if (!input) return;
  const isPass = input.type === 'password';
  input.type = isPass ? 'text' : 'password';
  btn.innerHTML = isPass
    ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/></svg>'
    : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
}

/* ═══════════════════════════════════════════════════════════
   Custom color
   ═══════════════════════════════════════════════════════════ */
function hexToRgb(hex) {
  const r = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(hex);
  return r ? `${parseInt(r[1],16)},${parseInt(r[2],16)},${parseInt(r[3],16)}` : null;
}
function lighten(hex, pct) {
  const r = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(hex);
  if (!r) return hex;
  const l = v => Math.min(255, Math.round(parseInt(v,16) + (255 - parseInt(v,16)) * (pct / 100)));
  return `#${[r[1],r[2],r[3]].map(v => l(v).toString(16).padStart(2,'0')).join('')}`;
}
function applyAccentColor(card, hex) {
  const rgb = hexToRgb(hex);
  if (!rgb) return;
  card.style.cssText = `
    --card-color:${hex};
    --card-color-l:${lighten(hex, 20)};
    --card-bg:rgba(${rgb},.08);
    --card-bg-h:rgba(${rgb},.15);
    --card-border:rgba(${rgb},.25);
    --card-shadow:rgba(${rgb},.18);
  `;
}

/* ═══════════════════════════════════════════════════════════
   sanitizePath
   ═══════════════════════════════════════════════════════════ */
const ALLOWED_PATH_RE = /^(\/[\w\-./]*|[\w\-][\w\-./]*)$/;
function sanitizePath(path) {
  if (typeof path !== 'string') return null;
  const s = path.trim();
  if (!s) return null;
  if (/^(javascript:|data:|vbscript:|blob:)/i.test(s)) return null;
  if (s.includes('..')) return null;
  if (/^https?:\/\/.+/i.test(s)) return s;
  if (!ALLOWED_PATH_RE.test(s)) return null;
  return s;
}
function isExternalUrl(path) {
  return /^https?:\/\//i.test(path);
}

/* sanitizeNotifHtml + NotifDetail (the notification detail modal) live in
   assets/js/notif-detail.js, shared with the notifications page. */

/* ═══════════════════════════════════════════════════════════
   State
   ═══════════════════════════════════════════════════════════ */
let activeFilter = FILTER_ALL;
let allToolsList = [];
let ICONS        = {};
let SVG_CACHE    = null;
let assetsCache  = null;

/* ═══════════════════════════════════════════════════════════
   Auth state + User Menu
   ═══════════════════════════════════════════════════════════ */
const Auth = {
  loggedIn:    false,
  displayName: '',
  username:    '',
  email:       '',
  isAdmin:     false,

  setLoggedIn(displayName, username = '', isAdmin = false, email = '') {
    this.loggedIn    = true;
    this.displayName = displayName;
    this.username    = username;
    this.email       = email;
    this.isAdmin     = !!isAdmin;
    this._updateUI();
  },
  setLoggedOut() {
    this.loggedIn    = false;
    this.displayName = '';
    this.username    = '';
    this.email       = '';
    this.isAdmin     = false;
    this._updateUI();
  },
  _updateUI() {
    const authBtn      = document.getElementById('authBtn');
    const userMenuWrap = document.getElementById('userMenuWrap');

    if (this.loggedIn) {
      if (authBtn)      authBtn.style.display      = 'none';
      if (userMenuWrap) userMenuWrap.style.display  = 'flex';

      const display = this.displayName || this.username || '';

      const name   = document.getElementById('userMenuName');
      const dName  = document.getElementById('dropdownDisplayName');
      const dUname = document.getElementById('dropdownUsername');

      if (name)   name.textContent   = display;
      if (dName)  dName.textContent  = display;
      if (dUname) dUname.textContent = this.email || this.username;

      const adminLink = document.getElementById('adminPanelLink');
      if (adminLink) adminLink.style.display = this.isAdmin ? '' : 'none';
    } else {
      if (authBtn)      authBtn.style.display      = '';
      if (userMenuWrap) userMenuWrap.style.display  = 'none';
      const adminLink = document.getElementById('adminPanelLink');
      if (adminLink) adminLink.style.display = 'none';
      UserMenu.close();
    }

    // Sync server-rendered admin reorder controls with current admin state —
    // on admin logout without a refresh, the "reorder" button/bar must not stay visible.
    const reorderToggle = document.getElementById('reorderToggle');
    if (reorderToggle) reorderToggle.style.display = this.isAdmin ? '' : 'none';
    if (!this.isAdmin) {
      const reorderBar = document.getElementById('reorderBar');
      if (reorderBar) reorderBar.hidden = true;
      const g = document.getElementById('toolsGrid');
      if (g) g.classList.remove('reordering');
      if (window.AdminTools) AdminTools._reordering = false;
    }
  },
};

/* ═══════════════════════════════════════════════════════════
   User Menu Dropdown
   ═══════════════════════════════════════════════════════════ */
const UserMenu = {
  _open: false,

  toggle() { this._open ? this.close() : this.open(); },
  open() {
    this._open = true;
    const btn      = document.getElementById('userMenuBtn');
    const dropdown = document.getElementById('userMenuDropdown');
    if (btn)      btn.setAttribute('aria-expanded', 'true');
    if (dropdown) { dropdown.classList.add('open'); dropdown.setAttribute('aria-hidden', 'false'); }
  },
  close() {
    this._open = false;
    const btn      = document.getElementById('userMenuBtn');
    const dropdown = document.getElementById('userMenuDropdown');
    if (btn)      btn.setAttribute('aria-expanded', 'false');
    if (dropdown) { dropdown.classList.remove('open'); dropdown.setAttribute('aria-hidden', 'true'); }
  },
};

/* ═══════════════════════════════════════════════════════════
   Notification Panel
   ═══════════════════════════════════════════════════════════ */
const NotifPanel = {
  _open:          false,
  _notifications: [],       // flat, accumulated across pages (no client-side re-slicing)
  _unreadCount:   0,
  _nextCursor:    null,      // Cursor string for the next page, or null if none/unknown yet
  _hasMore:       false,
  _scrolledDeeper: false,    // has the user loaded a 2nd+ page since the last fresh load()?
  _pollTimer:     null,
  _POLL_MS:        25000,  // minimum poll interval: 25s
  _POLL_JITTER_MS: 5000,   // random jitter 0..5s per cycle (prevents clients from synchronizing)
  _PAGE_SIZE:      20,
  _loaded:        false,   // has at least one fetch completed (skeleton vs. real empty state)
  _loading:       false,   // guard against concurrent first-page loads
  _loadingMore:   false,   // guard against concurrent "load next page" calls
  _scrollHandler: null,

  /** Fresh load of the first page — replaces (not appends to) the current list. */
  async load() {
    if (this._loading) return;
    this._loading = true;
    try {
      const page = await this._fetchPage(null);
      if (page) {
        this._notifications  = page.notifications;
        this._nextCursor     = page.next_cursor;
        this._hasMore         = page.has_more;
        this._scrolledDeeper = false;
      }
      this._loaded = true;

      const cRes  = await fetch(`${API_URL}?action=unread_count`, { cache: 'no-cache' });
      const cData = await cRes.json();
      if (cData.ok) this._unreadCount = cData.count || 0;
      this._updateBadge();
      if (this._open) this._renderDropdown();
    } catch {
      // on error, don't clear previous state (it may still be valid from an earlier load)
      this._updateBadge();
    } finally {
      this._loading = false;
    }
  },

  /** Infinite scroll: fetch the next page and append it to the already-rendered list. */
  async _loadMore() {
    if (this._loadingMore || !this._hasMore || !this._nextCursor) return;
    this._loadingMore = true;
    this._renderDropdown(); // shows the trailing "loading more" skeleton row

    const before = this._notifications.length;
    try {
      const page = await this._fetchPage(this._nextCursor);
      if (page) {
        this._notifications.push(...page.notifications);
        this._nextCursor     = page.next_cursor;
        this._hasMore        = page.has_more;
        this._scrolledDeeper = true;
        this._announce(
          page.notifications.length === 1
            ? 'یک اعلان دیگر بارگذاری شد'
            : `${page.notifications.length} اعلان دیگر بارگذاری شد`
        );
      }
    } finally {
      this._loadingMore = false;
      this._renderDropdown();
      // restore scroll position relative to content instead of jumping to the top
      const body = document.getElementById('notifDropdownBody');
      if (body && before > 0) {
        const anchor = body.children[before - 1];
        if (anchor) anchor.scrollIntoView({ block: 'start' });
      }
    }
  },

  /** Shared fetch + ETag handling for one page (cursor=null → first page). */
  async _fetchPage(cursor) {
    let url = `${API_URL}?action=notifications&limit=${this._PAGE_SIZE}`;
    if (cursor) url += `&before=${encodeURIComponent(cursor)}`;
    try {
      const res = await fetch(url, { cache: 'no-cache' });
      if (res.status === 304) return null; // nothing changed since the last identical request
      const data = await res.json();
      if (!data.ok) return null;
      return {
        notifications: data.notifications || [],
        has_more:      !!data.has_more,
        next_cursor:   data.next_cursor || null,
      };
    } catch {
      return null;
    }
  },

  reset() {
    this._notifications  = [];
    this._unreadCount    = 0;
    this._nextCursor     = null;
    this._hasMore        = false;
    this._scrolledDeeper = false;
    this._loaded         = false;   // so it reloads on the next login/logout
    this._updateBadge();
    this.close();
  },

  // ── Realtime polling ──────────────────────────────────────
  // A setTimeout chain with random jitter (instead of a fixed setInterval) so polls
  // from thousands of open tabs don't synchronize and cause a request burst on the server.
  startPolling() {
    this.stopPolling();
    const tick = () => {
      this._pollTimer = setTimeout(() => {
        if (!document.hidden) this._poll();
        tick();
      }, this._POLL_MS + Math.floor(Math.random() * this._POLL_JITTER_MS));
    };
    tick();
  },

  stopPolling() {
    if (this._pollTimer) { clearTimeout(this._pollTimer); this._pollTimer = null; }
  },

  async _poll() {
    try {
      // count + identity (me) both ride along in the same response — if the admin or the
      // user themself edits the name/email/role, it updates on this page within ~25s
      // without needing a logout/login or manual refresh.
      const countRes = await fetch(`${API_URL}?action=unread_count`, { cache: 'no-cache' });
      const data = await countRes.json();
      if (!data.ok) return;

      // The session may have expired between two polls (either the TTL ran out or an admin
      // ended it manually) even while the user is still actively using the page. Refreshing
      // this same page forces the server to redirect to /login.
      if (data.logged_in === false) {
        Auth.setLoggedOut();
        this.reset();
        location.reload();
        return;
      }

      const meData = data.me;
      if (meData) {
        const changed =
          meData.display_name !== Auth.displayName ||
          meData.username     !== Auth.username    ||
          meData.email        !== Auth.email        ||
          !!meData.is_admin   !== Auth.isAdmin;
        if (changed) {
          Auth.setLoggedIn(meData.display_name || '', meData.username || '', meData.is_admin, meData.email || '');
        }
      }

      const newCount = data.count || 0;
      if (newCount !== this._unreadCount) {
        this._unreadCount = newCount;
        this._updateBadge();
        // Only silently refresh the rendered list if the user hasn't scrolled past the
        // first page — replacing/reordering content out from under someone actively
        // scrolling through older items would be jarring. If they have scrolled deeper,
        // the list simply refreshes fresh the next time the panel is reopened (open()
        // always reloads page 1), and the badge above already reflects the true count.
        if (this._open && !this._scrolledDeeper) {
          await this.load();
        }
      }
    } catch { /* silent */ }
  },

  _updateBadge() {
    const badge  = document.getElementById('notifBellBadge');
    const bellBtn = document.getElementById('notifBellBtn');
    if (!badge) return;
    const count = this._unreadCount;
    if (count > 0) {
      badge.textContent   = count > 99 ? '99+' : String(count);
      badge.style.display = 'flex';
      bellBtn?.classList.add('has-unread');
    } else {
      badge.style.display = 'none';
      bellBtn?.classList.remove('has-unread');
    }
  },

  toggle() { this._open ? this.close() : this.open(); },

  open() {
    this._open = true;
    // Every fresh open re-fetches page 1 (cheap: ETag/304 short-circuits when nothing
    // changed) instead of reusing whatever was last loaded — so reopening the panel
    // always shows current data, without needing to silently rewrite an actively-scrolled
    // list (see _poll()'s _scrolledDeeper guard for why that's avoided while open).
    this.load();

    const btn      = document.getElementById('notifBellBtn');
    const dropdown = document.getElementById('notifDropdown');
    const body     = document.getElementById('notifDropdownBody');
    if (btn)      btn.setAttribute('aria-expanded', 'true');
    if (dropdown) {
      this._renderDropdown();
      dropdown.classList.add('open');
      dropdown.setAttribute('aria-hidden', 'false');
    }

    // Infinite scroll: fetch the next page once the user scrolls near the bottom
    // (same threshold/technique already used by the admin panel's readers/sessions modals).
    if (body) {
      if (this._scrollHandler) body.removeEventListener('scroll', this._scrollHandler);
      this._scrollHandler = () => {
        if (body.scrollTop + body.clientHeight >= body.scrollHeight - 80) this._loadMore();
      };
      body.addEventListener('scroll', this._scrollHandler);
    }

    UserMenu.close();
  },

  close() {
    this._open = false;
    const btn      = document.getElementById('notifBellBtn');
    const dropdown = document.getElementById('notifDropdown');
    const body     = document.getElementById('notifDropdownBody');
    if (btn)      btn.setAttribute('aria-expanded', 'false');
    if (dropdown) { dropdown.classList.remove('open'); dropdown.setAttribute('aria-hidden', 'true'); }
    if (body && this._scrollHandler) body.removeEventListener('scroll', this._scrollHandler);
  },

  /** Screen-reader-only announcement (e.g. "3 more notifications loaded") for the
   *  infinite-scroll region — new content that streams in silently on scroll would
   *  otherwise be invisible to assistive tech. */
  _announce(msg) {
    const live = document.getElementById('notifLiveRegion');
    if (live) live.textContent = msg;
  },

  _renderDropdown() {
    const body = document.getElementById('notifDropdownBody');
    if (!body) return;

    const list = this._notifications;

    if (!list.length) {
      body.innerHTML = this._loaded
        ? `<div class="notif-drop-empty">
             <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
               <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
               <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
             </svg>
             <p>اعلانی برای نمایش وجود ندارد</p>
           </div>`
        : SKELETON_LIST_ITEM.repeat(3);
      body.setAttribute('aria-busy', this._loaded ? 'false' : 'true');
      return;
    }
    body.setAttribute('aria-busy', 'false');

    body.innerHTML = '';
    const frag = document.createDocumentFragment();

    list.forEach(n => {
      const item = document.createElement('div');
      item.className  = `notif-drop-item${n.is_read ? '' : ' notif-drop-item--unread'}`;
      item.dataset.id = n.id;
      item.setAttribute('role', 'listitem');

      const ago     = this._timeAgo(n.created_at);
      const hasImg  = !!(n.image_path);

      item.innerHTML = `
        <div class="notif-drop-bar" aria-hidden="true"></div>
        <div class="notif-drop-content">
          <div class="notif-drop-title">${this._esc(n.title)}</div>
          <div class="notif-drop-time">
            ${ago}${hasImg ? ' &nbsp;·&nbsp; <span style="opacity:.7;" aria-label="دارای تصویر"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></svg></span>' : ''}
          </div>
        </div>
        <button class="notif-drop-view-btn"
                aria-label="مشاهده: ${this._esc(n.title)}">
          مشاهده
        </button>
      `;

      const openDetail = () => {
        this.close();
        NotifDetail.open(n);
        if (!n.is_read) {
          this._markReadSilent(n.id, item, n);
        }
      };

      const viewBtn = item.querySelector('.notif-drop-view-btn');
      viewBtn.addEventListener('click', openDetail);

      frag.appendChild(item);
    });

    if (this._loadingMore) {
      const sentinel = document.createElement('div');
      sentinel.innerHTML = SKELETON_LIST_ITEM;
      frag.appendChild(sentinel.firstElementChild);
    }

    body.appendChild(frag);
  },

  // mark read without closing the modal or touching the (already-closed) dropdown UI
  async _markReadSilent(id, itemEl, notifObj) {
    if (notifObj) notifObj.is_read = true;
    this._unreadCount = Math.max(0, this._unreadCount - 1);

    // an expired notification is removed from the active list once read
    // (mirrors server behavior in allActiveForUser) so it disappears without a page refresh
    if (notifObj && notifObj.is_expired) {
      this._notifications = this._notifications.filter(x => x.id !== id);
    }

    this._updateBadge();
    if (this._open) this._renderDropdown();

    try {
      await fetch(`${API_URL}?action=mark_read`, {
        method:  'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.CSRF_TOKEN || '' },
        body:    JSON.stringify({ notification_id: id }),
      });
    } catch { /* silent */ }
  },

  async markAllRead() {
    if (!Auth.loggedIn) return;
    this._notifications.forEach(n => { n.is_read = true; });
    this._unreadCount = 0;
    this._updateBadge();
    this._renderDropdown();
    try {
      await fetch(`${API_URL}?action=mark_all_read`, {
        method: 'POST',
        headers: { 'X-CSRF-Token': window.CSRF_TOKEN || '' },
      });
    } catch { /* silent */ }
  },

  _esc(str) {
    return String(str ?? '')
      .replace(/&/g,'&amp;').replace(/</g,'&lt;')
      .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  },

  _timeAgo(dateStr) {
    const diff = Math.floor((Date.now() - new Date(dateStr).getTime()) / 1000);
    if (diff <    60) return 'همین الان';
    if (diff <  3600) return `${Math.floor(diff / 60)} دقیقه پیش`;
    if (diff < 86400) return `${Math.floor(diff / 3600)} ساعت پیش`;
    if (diff < 2592000) return `${Math.floor(diff / 86400)} روز پیش`;
    return DateFmt.date(dateStr);
  },
};

/* ═══════════════════════════════════════════════════════════
   DOM refs
   ═══════════════════════════════════════════════════════════ */
const grid        = document.getElementById('toolsGrid');
const searchInput = document.getElementById('search');
const clearButton = document.getElementById('clearSearch');
const toolCount   = document.getElementById('toolCount');
const filterBar   = document.getElementById('filterBar');
const mainContent = document.getElementById('main-content');

/* ═══════════════════════════════════════════════════════════
   SVG Cache
   ═══════════════════════════════════════════════════════════ */
function buildCache(iconsData, decosData) {
  const cornerSVG = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
  cornerSVG.setAttribute('viewBox', '0 0 110 110');
  cornerSVG.setAttribute('aria-hidden', 'true');
  cornerSVG.setAttribute('focusable', 'false');
  cornerSVG.innerHTML = `
    <path class="corner-sector"    d="M0,0 L80,0 A80,80,0,0,1,0,80 Z"/>
    <path class="corner-arc-outer" d="M68,0 A68,68,0,0,1,0,68"/>
    <path class="corner-arc-inner" d="M46,0 A46,46,0,0,1,0,46"/>
    <circle class="corner-dot corner-dot-1" cx="78" cy="16" r="4"/>
    <circle class="corner-dot corner-dot-2" cx="90" cy="34" r="3"/>
    <circle class="corner-dot corner-dot-3" cx="64" cy="8"  r="2.5"/>
    <circle class="corner-dot corner-dot-4" cx="96" cy="52" r="2.5"/>
    <circle class="corner-dot corner-dot-5" cx="52" cy="4"  r="2"/>
  `;

  const arrowSVG = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
  arrowSVG.setAttribute('viewBox', '0 0 24 24');
  arrowSVG.setAttribute('aria-hidden', 'true');
  arrowSVG.innerHTML = '<path d="M14 5l-1.41 1.41L17.17 11H3v2h14.17l-4.58 4.59L14 19l7-7z" fill="currentColor"/>';

  const parser    = new DOMParser();
  const decoNodes = {};
  for (const [key, html] of Object.entries(decosData)) {
    decoNodes[key] = parser.parseFromString(html, 'text/html').body.firstChild;
  }

  ICONS = iconsData;
  return { cornerSVG, arrowSVG, decoNodes };
}

function makeSVG(key, size = 24) {
  const inner = ICONS[key] || ICONS[ICON_FALLBACK] || '';
  return `<svg viewBox="0 0 24 24" width="${size}" height="${size}" aria-hidden="true" focusable="false">${inner}</svg>`;
}

/* Some card decos (cacti, radarPing, ipmanagment, ipband, servermonitor, hostmonitor)
   use internal ids referenced by <mpath href="#id"> (SMIL "move along this path").
   Every tool sharing that deco clones the same node, so without renaming, every clone
   after the first has a duplicate id — the browser resolves href="#id" to the FIRST
   matching id in the whole document, so the animateMotion on every later card follows
   the first card's path instead of its own, which reads as a broken/incomplete deco. */
let decoIdSeq = 0;
function uniquifyDecoIds(svgEl) {
  const idEls = svgEl.querySelectorAll('[id]');
  if (!idEls.length) return;
  const suffix = '-c' + (++decoIdSeq);
  const map = new Map();
  idEls.forEach(el => { const oldId = el.id; el.id = oldId + suffix; map.set(oldId, el.id); });
  svgEl.querySelectorAll('[href], [xlink\\:href]').forEach(el => {
    for (const attr of ['href', 'xlink:href']) {
      const val = el.getAttribute(attr);
      if (val && val.charAt(0) === '#' && map.has(val.slice(1))) {
        el.setAttribute(attr, '#' + map.get(val.slice(1)));
      }
    }
  });
}

/* ═══════════════════════════════════════════════════════════
   Filter chips
   ═══════════════════════════════════════════════════════════ */
function buildFilterChips() {
  filterBar.querySelectorAll(`.chip:not([data-filter="${FILTER_ALL}"])`).forEach(c => c.remove());
  const badges = [...new Set(allToolsList.map(t => t.badge).filter(Boolean))];
  badges.forEach(badge => {
    const btn = document.createElement('button');
    btn.className      = 'chip';
    btn.dataset.filter = badge;
    btn.textContent    = badge;
    btn.addEventListener('click', () => setFilter(badge));
    filterBar.appendChild(btn);
  });
  filterBar.querySelector(`[data-filter="${FILTER_ALL}"]`).onclick = () => setFilter(FILTER_ALL);
}

function setFilter(f) {
  if (f === activeFilter) return;
  activeFilter = f;
  filterBar.querySelectorAll('.chip').forEach(c =>
    c.classList.toggle('active', c.dataset.filter === f)
  );
  renderTools(searchInput.value);
}

/* ── SMIL pause helper ──
   `animation-play-state: paused` (used everywhere else to stop deco animation) only
   affects CSS @keyframes — several decos (radarPing, ipmanagment, cacti, ipband,
   servermonitor, hostmonitor, ssl, pfx, bitninja, loganalyzer) instead use native SVG
   SMIL (<animate>/<animateTransform>/<animateMotion>), which keeps running regardless
   of that CSS rule and needs the SVG root's own pauseAnimations()/unpauseAnimations(). */
function pauseSMIL(scope) {
  scope.querySelectorAll('svg').forEach(s => { try { s.pauseAnimations(); } catch (_) {} });
}
function unpauseSMIL(scope) {
  scope.querySelectorAll('svg').forEach(s => { try { s.unpauseAnimations(); } catch (_) {} });
}
/* Same as unpauseSMIL, but respects other modes that keep animation paused on purpose:
   - reorder mode (dragging cards) pauses everything itself and manages its own resume
     (on exit); a scroll mid-drag (the edge auto-scroll) must not undo that.
   - "calm mode" (30+ cards, deco only animates on hover/focus — see .grid--calm in
     style.css) a card whose CSS state is still paused must stay paused, or scroll/
     visibility resuming it would fight the CSS rule and run the SMIL invisibly
     (wasting CPU) or visibly out of sync. */
function resumeSMIL(scope) {
  if (grid.classList.contains('reordering')) return;
  const calm = grid.classList.contains('grid--calm');
  scope.querySelectorAll('svg').forEach(s => {
    if (calm) {
      const card = s.closest('.card');
      if (card && !card.matches(':hover') && !card.contains(document.activeElement)) return;
    }
    try { s.unpauseAnimations(); } catch (_) {}
  });
}

/* ═══════════════════════════════════════════════════════════
   Card visibility observer — pause off-screen card animation
   ═══════════════════════════════════════════════════════════ */
/* ── pause deco animation during scroll ──
   While scrolling, SVG animation repaints compete with the scroll itself and cause lag.
   Adding the is-scrolling class to <html> temporarily stops all deco animations,
   which resume ~150ms after scrolling stops. (Harmless if there are no cards.) */
(function () {
  const root = document.documentElement;
  let scrolling = false, raf = 0, off = 0;
  function onScroll() {
    if (!scrolling && !raf) {
      raf = requestAnimationFrame(() => {
        raf = 0; scrolling = true; root.classList.add('is-scrolling');
        pauseSMIL(grid);
      });
    }
    clearTimeout(off);
    off = setTimeout(() => {
      scrolling = false; root.classList.remove('is-scrolling');
      resumeSMIL(grid);
    }, 150);
  }
  window.addEventListener('scroll', onScroll, { passive: true });
})();

let cardVisibilityObserver = null;
function getCardVisibilityObserver() {
  if (cardVisibilityObserver) return cardVisibilityObserver;
  if (typeof IntersectionObserver === 'undefined') return null;
  cardVisibilityObserver = new IntersectionObserver(entries => {
    for (const entry of entries) {
      const goingOffscreen = !entry.isIntersecting;
      entry.target.classList.toggle('card--offscreen', goingOffscreen);
      // is-scrolling already paused everything; avoid unpausing offscreen cards mid-scroll
      if (goingOffscreen) pauseSMIL(entry.target);
      else if (!document.documentElement.classList.contains('is-scrolling')) resumeSMIL(entry.target);
    }
  }, { rootMargin: '300px 0px', threshold: 0 });
  return cardVisibilityObserver;
}

/* ═══════════════════════════════════════════════════════════
   Render
   ═══════════════════════════════════════════════════════════ */
/* ── lazy loading: cards are built in batches of BATCH_SIZE, and the
      next batch only renders once the user scrolls near the end of the list.
      This avoids building hundreds of cards + deco animations all at once. */
const BATCH_SIZE = 12;
/* "calm mode" threshold: above this many cards, deco animation only runs on the
   hovered card (the rest stay still) to avoid lag with 50-100+ cards. Adjustable. */
const MOTION_THRESHOLD = 30;
let loadMoreObserver = null;
let renderQueue = { list: [], rendered: 0, sentinel: null };

function getLoadMoreObserver() {
  if (loadMoreObserver) return loadMoreObserver;
  if (typeof IntersectionObserver === 'undefined') return null;
  loadMoreObserver = new IntersectionObserver(entries => {
    if (entries.some(e => e.isIntersecting)) renderNextBatch();
  }, { rootMargin: '600px 0px', threshold: 0 });
  return loadMoreObserver;
}

function renderNextBatch() {
  const { list, rendered } = renderQueue;
  const slice = list.slice(rendered, rendered + BATCH_SIZE);
  if (!slice.length) return;

  renderQueue.sentinel?.remove();
  renderQueue.sentinel = null;

  const frag     = document.createDocumentFragment();
  const newCards = [];
  for (const t of slice) {
    const c = createCard(t);
    newCards.push(c);
    frag.appendChild(c);
  }
  grid.appendChild(frag);
  renderQueue.rendered += slice.length;

  // only observe the newly added cards, for off-screen animation pausing
  const obs = getCardVisibilityObserver();
  if (obs) newCards.forEach(c => obs.observe(c));

  // if cards remain, create and observe a sentinel to load the next batch
  if (renderQueue.rendered < list.length) {
    const lm = getLoadMoreObserver();
    if (lm) {
      const sentinel = document.createElement('div');
      sentinel.className = 'grid-sentinel';
      sentinel.setAttribute('aria-hidden', 'true');
      grid.appendChild(sentinel);
      renderQueue.sentinel = sentinel;
      lm.observe(sentinel);
    } else {
      renderNextBatch(); // no IntersectionObserver: build everything at once
    }
  }
}

function renderTools(filterText = '') {
  // disconnect observers before clearing the DOM (prevents a memory leak)
  cardVisibilityObserver?.disconnect();
  loadMoreObserver?.disconnect();

  grid.textContent = '';

  // fixed "add tool" tile — always first in the grid, for admins
  if (window.AdminTools && AdminTools.enabled) grid.appendChild(AdminTools.makeAddTile());

  const q = filterText.trim().toLowerCase();

  let list = activeFilter === FILTER_ALL
    ? allToolsList
    : allToolsList.filter(t => t.badge === activeFilter);

  if (q) {
    list = list.filter(t =>
      t.title.toLowerCase().includes(q) ||
      (t.description || '').toLowerCase().includes(q) ||
      (t.badge || '').toLowerCase().includes(q)
    );
  }

  if (toolCount) toolCount.textContent = String(list.length);

  // adaptive calm mode: with many cards, deco animation only runs on hover
  grid.classList.toggle('grid--calm', list.length > MOTION_THRESHOLD);

  if (!list.length) { showEmptyState(q); return; }

  renderQueue = { list, rendered: 0, sentinel: null };
  renderNextBatch();
}

/* ═══════════════════════════════════════════════════════════
   Create card
   ═══════════════════════════════════════════════════════════ */
function createCard(tool) {
  const card = document.createElement('div');
  card.className = 'card';
  card.setAttribute('role', 'listitem');
  card.setAttribute('tabindex', '0');
  card.setAttribute('aria-label', `${tool.title}: ${tool.description || ''}`);

  if (tool.accentColor) {
    applyAccentColor(card, tool.accentColor);
  } else if (tool.badge) {
    card.dataset.badge = tool.badge;
  }

  const cornerWrap = document.createElement('div');
  cornerWrap.className = 'card-corner-deco';
  cornerWrap.setAttribute('aria-hidden', 'true');
  cornerWrap.appendChild(SVG_CACHE.cornerSVG.cloneNode(true));

  const iconEl = document.createElement('div');
  iconEl.className = 'card-icon';
  iconEl.innerHTML = makeSVG(tool.iconKey || ICON_FALLBACK);

  const badge = document.createElement('span');
  badge.className   = 'card-badge';
  badge.textContent = tool.badge || BADGE_FALLBACK;

  const title = document.createElement('h3');
  title.textContent = tool.title;

  const desc = document.createElement('p');
  desc.textContent = tool.description || '';

  const decoWrap = document.createElement('div');
  decoWrap.className = 'card-deco-wrap';
  const decoNode = SVG_CACHE.decoNodes[tool.deco] || SVG_CACHE.decoNodes[DECO_FALLBACK];
  const decoClone = decoNode.cloneNode(true);
  uniquifyDecoIds(decoClone);
  decoWrap.appendChild(decoClone);

  const arrow = document.createElement('div');
  arrow.className = 'card-arrow';
  arrow.appendChild(SVG_CACHE.arrowSVG.cloneNode(true));

  card.append(cornerWrap, iconEl, badge, title, desc, decoWrap, arrow);

  // calm mode (30+ cards): deco SMIL only runs on hover/focus, mirroring the CSS rule
  // that keeps CSS @keyframes decos paused otherwise — animation-play-state has no
  // effect on SMIL, so it needs these explicit pause/unpauseAnimations() calls too.
  if (grid.classList.contains('grid--calm')) {
    pauseSMIL(card);
    // hover/drag during reorder mode must NOT wake it back up — dragging a card
    // over others fires mouseenter/mouseleave on them just like a real hover
    const wake  = () => {
      if (!document.documentElement.classList.contains('is-scrolling') && !grid.classList.contains('reordering')) {
        unpauseSMIL(card);
      }
    };
    const sleep = () => pauseSMIL(card);
    card.addEventListener('mouseenter', wake);
    card.addEventListener('mouseleave', sleep);
    card.addEventListener('focusin',    wake);
    card.addEventListener('focusout',   sleep);
  }

  const safePath = sanitizePath(tool.path);
  if (safePath) {
    const external = isExternalUrl(safePath);
    const go = () => window.open(safePath, '_blank', 'noopener,noreferrer');

    if (external) {
      const extBadge = document.createElement('span');
      extBadge.className = 'card-external-badge';
      extBadge.setAttribute('aria-label', 'لینک خارجی');
      extBadge.setAttribute('title', 'لینک خارجی — در تب جدید باز می‌شود');
      extBadge.innerHTML = '<svg viewBox="0 0 24 24" width="10" height="10" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>';
      card.appendChild(extBadge);
      card.classList.add('card--external');
    }

    card.addEventListener('click', go);
    card.addEventListener('keydown', e => {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); go(); }
    });
  }

  // inline management controls (only when an admin is logged in)
  if (window.AdminTools && AdminTools.enabled) AdminTools.decorateCard(card, tool);

  return card;
}

/* ═══════════════════════════════════════════════════════════
   Empty / Error state
   ═══════════════════════════════════════════════════════════ */
function showEmptyState(query) {
  const hasFilter = activeFilter !== FILTER_ALL;
  const msg = query
    ? `نتیجه‌ای برای «${query}» یافت نشد.`
    : hasFilter
      ? `ابزاری در دسته «${activeFilter}» یافت نشد.`
      : 'هیچ ابزاری یافت نشد.';

  const wrap = document.createElement('div');
  wrap.className = 'empty-state';
  wrap.setAttribute('role', 'status');

  const icon = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
  icon.setAttribute('viewBox', '0 0 24 24');
  icon.setAttribute('aria-hidden', 'true');
  icon.innerHTML = '<path d="M10 2a8 8 0 105.293 14.293l4.707 4.707 1.414-1.414-4.707-4.707A8 8 0 0010 2zm0 2a6 6 0 110 12A6 6 0 0110 4z"/>';

  const msgEl = document.createElement('p');
  msgEl.textContent = msg;
  wrap.append(icon, msgEl);

  if (query || hasFilter) {
    const r = document.createElement('span');
    r.className = 'reset-link';
    r.setAttribute('role', 'button');
    r.setAttribute('tabindex', '0');
    r.textContent = 'پاک کردن فیلترها';
    r.addEventListener('click', resetAll);
    r.addEventListener('keydown', e => {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); resetAll(); }
    });
    wrap.appendChild(r);
  }

  grid.appendChild(wrap);
}

function showErrorState() {
  grid.innerHTML = '';
  const wrap = document.createElement('div');
  wrap.className = 'empty-state';
  wrap.setAttribute('role', 'alert');

  const icon = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
  icon.setAttribute('viewBox', '0 0 24 24');
  icon.setAttribute('aria-hidden', 'true');
  icon.innerHTML = '<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>';

  const msgEl = document.createElement('p');
  msgEl.textContent = 'خطا در بارگذاری ابزارها. لطفا صفحه را دوباره بارگذاری کنید.';

  const btn = document.createElement('span');
  btn.className = 'reset-link';
  btn.setAttribute('role', 'button');
  btn.setAttribute('tabindex', '0');
  btn.textContent = 'تلاش مجدد';
  btn.addEventListener('click', () => location.reload());

  wrap.append(icon, msgEl, btn);
  grid.appendChild(wrap);
  if (toolCount) toolCount.textContent = '0';
}

function resetAll() {
  searchInput.value = '';
  activeFilter = FILTER_ALL;
  filterBar.querySelectorAll('.chip').forEach(c =>
    c.classList.toggle('active', c.dataset.filter === FILTER_ALL)
  );
  handleSearch('');
  searchInput.focus();
}

/* ═══════════════════════════════════════════════════════════
   Search
   ═══════════════════════════════════════════════════════════ */
let searchTimer;
function handleSearch(val) { renderTools(val); toggleClear(val); }
function toggleClear(val) {
  const has = val.trim().length > 0;
  clearButton.classList.toggle('visible', has);
  clearButton.setAttribute('tabindex', has ? '0' : '-1');
}
function clearSearch() { searchInput.value = ''; handleSearch(''); searchInput.focus(); }

searchInput.addEventListener('input', e => {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => handleSearch(e.target.value), SEARCH_DEBOUNCE);
});
searchInput.addEventListener('paste', () => {
  setTimeout(() => handleSearch(searchInput.value), 0);
});
clearButton.addEventListener('click', clearSearch);

// ── search mode (Telegram-style): the #searchToggle icon opens a full-width search bar ──
const appHeader    = document.querySelector('.app-header');
const searchToggle = document.getElementById('searchToggle');
const searchClose  = document.getElementById('searchClose');
function openSearch()  { if (appHeader) appHeader.classList.add('searching'); setTimeout(() => searchInput.focus(), 50); }
function closeSearch() {
  if (appHeader) appHeader.classList.remove('searching');
  if (searchInput.value) { searchInput.value = ''; handleSearch(''); }
  searchInput.blur();
}
if (searchToggle) searchToggle.addEventListener('click', openSearch);
if (searchClose)  searchClose.addEventListener('click', closeSearch);

searchInput.addEventListener('keydown', e => {
  if (e.key === 'Escape') closeSearch();
});

mainContent.addEventListener('keydown', e => {
  if (e.key === '/' && document.activeElement !== searchInput && !e.ctrlKey && !e.metaKey) {
    e.preventDefault();
    openSearch();
  }
});

/* ═══════════════════════════════════════════════════════════
   Global click handler
   ═══════════════════════════════════════════════════════════ */
document.addEventListener('click', e => {

  // ── notification bell ──────────────────────────────────────────
  const bellBtn = e.target.closest('#notifBellBtn');
  if (bellBtn) {
    e.stopPropagation();
    NotifPanel.toggle();
    return;
  }

  // (closing the detail modal — close button + overlay click — is wired
  // directly in assets/js/notif-detail.js, shared with the notifications page)

  // ── click outside the notification panel — close it ──────────────────
  const notifWrap = e.target.closest('#notifBellWrap');
  if (!notifWrap && NotifPanel._open) {
    NotifPanel.close();
  }

  // ── user menu ─────────────────────────────────────────
  const menuBtn = e.target.closest('#userMenuBtn');
  if (menuBtn) {
    e.stopPropagation();
    NotifPanel.close();
    UserMenu.toggle();
    return;
  }

  // the "login" button is now a direct link to login.php (no JS needed)

  // ── logout button ──────────────────────────────────────────
  const logoutBtn = e.target.closest('#logoutBtn');
  if (logoutBtn) {
    UserMenu.close();
    handleLogout();
    return;
  }

  // ── click outside the user menu ─────────────────────────
  const menuWrap = e.target.closest('#userMenuWrap');
  if (!menuWrap) UserMenu.close();
});

/* ═══════════════════════════════════════════════════════════
   Keyboard: Escape
   ═══════════════════════════════════════════════════════════ */
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') {
    // priority: detail modal > notification panel > user menu > login modal
    const detailModal = document.getElementById('ndOverlay');
    if (detailModal?.classList.contains('open')) {
      NotifDetail.close(); return;
    }
    if (NotifPanel._open) {
      NotifPanel.close();
      document.getElementById('notifBellBtn')?.focus();
      return;
    }
    if (UserMenu._open) {
      UserMenu.close();
      document.getElementById('userMenuBtn')?.focus();
      return;
    }
  }
});

/* ═══════════════════════════════════════════════════════════
   Logout
   ═══════════════════════════════════════════════════════════ */
async function handleLogout() {
  try {
    await fetch(`${API_URL}?action=logout`, {
      method: 'POST',
      headers: { 'X-CSRF-Token': window.CSRF_TOKEN || '' },
    });
  } catch { /* network down — reload below re-tries the real state either way */ }

  // Every page requires a session now, so a reload is enough either way: if the logout
  // succeeded, the server-side guard on / redirects to /login; if it didn't (e.g. a stale
  // CSRF token → 403), the session is still alive and the page reloads normally as logged in.
  window.location.reload();
}

/* Login now happens on the separate login.php page (instead of a modal). */

/* ═══════════════════════════════════════════════════════════
   Skeleton
   ═══════════════════════════════════════════════════════════ */
const SKELETON_CARD =
  '<div class="skeleton-card" aria-hidden="true">'
  + '<div class="sk sk-icon"></div><div class="sk sk-badge"></div>'
  + '<div class="sk sk-title"></div><div class="sk sk-line"></div>'
  + '<div class="sk sk-line sk-line--short"></div></div>';

const SKELETON_LIST_ITEM =
  '<div class="sk-list-item" aria-hidden="true">'
  + '<div class="sk sk-list-icon"></div>'
  + '<div class="sk-list-lines"><div class="sk sk-line"></div><div class="sk sk-line sk-line--short"></div></div>'
  + '</div>';

function showSkeleton(n = 6) {
  // disconnect observers before clearing the DOM (prevents a memory leak)
  cardVisibilityObserver?.disconnect();
  loadMoreObserver?.disconnect();
  grid.innerHTML = SKELETON_CARD.repeat(n);
}

/* ═══════════════════════════════════════════════════════════
   loadData
   ═══════════════════════════════════════════════════════════ */
async function loadData() {
  showSkeleton();
  try {
    if (!assetsCache) {
      const ar    = await fetch(`${API_URL}?action=assets`);
      assetsCache = await ar.json();
      if (assetsCache.ok) SVG_CACHE = buildCache(assetsCache.icons, assetsCache.decos);
    }
    const res  = await fetch(`${API_URL}?action=tools`);
    const data = await res.json();
    if (!data.ok) throw new Error(data.msg || 'خطا');
    allToolsList = data.tools;
  } catch (err) {
    console.error('خطا در لود ابزارها:', err);
    allToolsList = [];
    showErrorState();
    return;
  }
  buildFilterChips();
  renderTools(searchInput.value);
}

/* ═══════════════════════════════════════════════════════════
   Init
   ───────────────────────────────────────────────────────────
   Optimized for high concurrency: all initial data (login state, assets,
   tools, notifications, count) is fetched in a "single" bootstrap request
   so only 1 connection opens instead of 5 network round trips.
   If bootstrap isn't available (older server version), it falls back
   to the multi-request approach.
   ═══════════════════════════════════════════════════════════ */
async function init() {
  showSkeleton();
  try {
    let data = null;
    try {
      const res = await fetch(`${API_URL}?action=bootstrap`, { cache: 'no-cache' });
      if (res.ok) data = await res.json();
    } catch { /* falling through to the fallback */ }

    if (data && data.ok) {
      applyBootstrap(data);
    } else {
      await initLegacy();   // fallback: separate individual requests
      return;
    }
  } catch (err) {
    console.error('خطا در لود اولیه:', err);
    Auth.setLoggedOut();
    allToolsList = [];
    showErrorState();
    return;
  }

  buildFilterChips();
  renderTools(searchInput.value);
}

/* applies the bootstrap response to state */
function applyBootstrap(data) {
  if (data.me && data.me.logged_in) {
    Auth.setLoggedIn(data.me.display_name || '', data.me.username || '', data.me.is_admin, data.me.email || '');
  } else {
    Auth.setLoggedOut();
  }

  if (data.assets && data.assets.ok) {
    assetsCache = data.assets;
    SVG_CACHE   = buildCache(data.assets.icons, data.assets.decos);
  }

  allToolsList = (data.tools && data.tools.ok) ? data.tools.tools : [];

  // Notifications no longer ride along in the bootstrap response (so cards don't wait on ~105KB).
  // Only the initial count (for logged-in users) is set, so the badge appears immediately;
  // the full list loads in the background via startRealtime().
  NotifPanel._unreadCount = (data.unread && data.unread.ok) ? (data.unread.count || 0) : 0;
  NotifPanel._updateBadge();

  startRealtime();
}

/* legacy approach (fallback): several parallel requests */
async function initLegacy() {
  try {
    const [meRes, assetsRes, toolsRes, notifRes, countRes] = await Promise.all([
      fetch(`${API_URL}?action=me`),
      fetch(`${API_URL}?action=assets`),
      fetch(`${API_URL}?action=tools`),
      fetch(`${API_URL}?action=notifications`),
      fetch(`${API_URL}?action=unread_count`),
    ]);

    const [meData, assetsData, toolsData, notifData, countData] = await Promise.all([
      meRes.json(), assetsRes.json(), toolsRes.json(), notifRes.json(), countRes.json(),
    ]);

    if (meData.ok && meData.logged_in) {
      Auth.setLoggedIn(meData.display_name || '', meData.username || '', meData.is_admin, meData.email || '');
    } else {
      Auth.setLoggedOut();
    }

    if (assetsData.ok) {
      assetsCache = assetsData;
      SVG_CACHE   = buildCache(assetsData.icons, assetsData.decos);
    }

    allToolsList = toolsData.ok ? toolsData.tools : [];

    if (notifData.ok) {
      NotifPanel._notifications = notifData.notifications || [];
      NotifPanel._nextCursor    = notifData.next_cursor || null;
      NotifPanel._hasMore       = !!notifData.has_more;
      NotifPanel._loaded        = true;
    }
    if (countData.ok) NotifPanel._unreadCount = countData.count || 0;
    NotifPanel._updateBadge();

    startRealtime();
  } catch (err) {
    console.error('خطا در لود اولیه (legacy):', err);
    Auth.setLoggedOut();
    allToolsList = [];
    showErrorState();
    return;
  }

  buildFilterChips();
  renderTools(searchInput.value);
}

/* starts realtime polling + an immediate check when the tab regains focus */
function startRealtime() {
  // non-blocking load of the notification list after cards render (no longer part of bootstrap)
  NotifPanel.load();
  NotifPanel.startPolling();
  document.addEventListener('visibilitychange', () => {
    if (!document.hidden) NotifPanel._poll();
  });
  window.addEventListener('focus', () => NotifPanel._poll());
}

/* ═══════════════════════════════════════════════════════════
   Inline tool management for admins (right on this dashboard)
   Active only when an admin is logged in and CSRF is available.
   Writes go to admin.php?api=add|edit|delete (role is checked from the DB).
   ═══════════════════════════════════════════════════════════ */
const AdminTools = {
  get enabled() { return !!(typeof Auth !== 'undefined' && Auth.isAdmin && window.CSRF_TOKEN); },
  _wired: false, _modal: null, _confirm: null, _delId: null,
  _sel: { icon: 'star', deco: 'generic', color: '' },
  _reordering: false, _reorderWired: false, _dragWired: false,
  _ph: null, _dragCard: null, _clone: null,
  _scrollRAF: null, _scrollDir: 0, _scrollSpeed: 0, _lastX: 0, _lastY: 0,

  _ic: {
    edit: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>',
    del:  '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>',
    plus: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>',
  },

  async call(action, body) {
    const res = await fetch('/admin.php?api=' + encodeURIComponent(action), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.CSRF_TOKEN || '' },
      body: JSON.stringify(body || {}),
    });
    try { return await res.json(); } catch (_) { return { ok: false, msg: 'خطا در ارتباط' }; }
  },

  decorateCard(card, tool) {
    card.classList.add('card--admin');
    card.dataset.toolId = tool.id;

    const bar = document.createElement('div');
    bar.className = 'card-admin-bar';

    const ed = document.createElement('button');
    ed.type = 'button'; ed.className = 'cab-btn cab-edit'; ed.title = 'ویرایش';
    ed.innerHTML = this._ic.edit;
    ed.addEventListener('click', (e) => { e.stopPropagation(); this.openEdit(tool); });

    const dl = document.createElement('button');
    dl.type = 'button'; dl.className = 'cab-btn cab-del'; dl.title = 'حذف';
    dl.innerHTML = this._ic.del;
    dl.addEventListener('click', (e) => { e.stopPropagation(); this.askDelete(tool.id, tool.title); });

    bar.append(ed, dl);
    card.appendChild(bar);
  },

  makeAddTile() {
    const tile = document.createElement('button');
    tile.type = 'button';
    tile.className = 'card card-add-tile';
    tile.setAttribute('aria-label', 'افزودن ابزار جدید');
    tile.innerHTML = this._ic.plus + '<span>افزودن ابزار</span>';
    tile.addEventListener('click', () => this.openAdd());
    return tile;
  },

  _ensureWired() {
    if (this._wired) return;
    this._modal   = document.getElementById('toolModal');
    this._confirm = document.getElementById('toolConfirm');
    if (!this._modal) return;
    const close = () => this.closeModal();
    document.getElementById('tmClose').addEventListener('click', close);
    document.getElementById('tmCancel').addEventListener('click', close);
    this._modal.addEventListener('click', (e) => { if (e.target === this._modal) close(); });
    document.getElementById('tmSave').addEventListener('click', () => this.save());
    // live preview while typing
    ['tmTitle', 'tmDesc', 'tmBadge'].forEach(id =>
      document.getElementById(id).addEventListener('input', () => this._updatePreview()));
    // live character counters (shown inside each textbox)
    ['tmTitle', 'tmDesc', 'tmBadge'].forEach(id =>
      document.getElementById(id).addEventListener('input', () => this._updateFieldCounters()));
    // color: presets + custom color
    document.getElementById('tmColorPresets').addEventListener('click', (e) => {
      const p = e.target.closest('.tm-preset');
      if (p) this._setColor(p.dataset.color || '');
    });
    document.getElementById('tmColor').addEventListener('input', (e) => {
      document.querySelectorAll('#tmColorPresets .tm-preset').forEach(b => b.classList.remove('active'));
      this._sel.color = e.target.value;
      this._updatePreview();
    });
    document.getElementById('tmConfirmClose').addEventListener('click', () => this._hideConfirm());
    document.getElementById('tmConfirmCancel').addEventListener('click', () => this._hideConfirm());
    document.getElementById('tmConfirmOk').addEventListener('click', () => this.doDelete());
    this._confirm.addEventListener('click', (e) => { if (e.target === this._confirm) this._hideConfirm(); });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') { this.closeModal(); this._hideConfirm(); this._hideUnsaved(); this._closeBadgeMenu(); this._closeIconMenu(); this._closeDecoMenu(); } });
    // category/icon/deco dropdowns: the chevron toggles open/closed, focusing
    // the box also opens it, and clicking outside the combobox closes it.
    const badgeSelect = document.getElementById('tmBadgeSelect');
    const badgeToggle = document.getElementById('tmBadgeToggle');
    const badgeInput  = document.getElementById('tmBadge');
    if (badgeToggle) badgeToggle.addEventListener('click', (e) => {
      e.stopPropagation();
      if (badgeSelect.classList.contains('open')) { this._closeBadgeMenu(); return; }
      this._renderBadgeMenu();
      this._openBadgeMenu();
    });
    if (badgeInput) {
      badgeInput.addEventListener('focus', () => { this._renderBadgeMenu(); this._openBadgeMenu(); });
      badgeInput.addEventListener('input', () => { this._renderBadgeMenu(); this._openBadgeMenu(); });
    }

    const iconSelect = document.getElementById('tmIconSelect');
    const iconToggle = document.getElementById('tmIconToggle');
    const iconInput  = document.getElementById('tmIcon');
    if (iconToggle) iconToggle.addEventListener('click', (e) => {
      e.stopPropagation();
      if (iconSelect.classList.contains('open')) { this._closeIconMenu(); return; }
      this._renderIconMenu();
      this._openIconMenu();
    });
    if (iconInput) {
      iconInput.addEventListener('focus', () => { this._renderIconMenu(); this._openIconMenu(); });
      iconInput.addEventListener('input', () => { this._renderIconMenu(); this._openIconMenu(); });
    }

    const decoSelect = document.getElementById('tmDecoSelect');
    const decoToggle = document.getElementById('tmDecoToggle');
    const decoInput  = document.getElementById('tmDeco');
    if (decoToggle) decoToggle.addEventListener('click', (e) => {
      e.stopPropagation();
      if (decoSelect.classList.contains('open')) { this._closeDecoMenu(); return; }
      this._renderDecoMenu();
      this._openDecoMenu();
    });
    if (decoInput) {
      decoInput.addEventListener('focus', () => { this._renderDecoMenu(); this._openDecoMenu(); });
      decoInput.addEventListener('input', () => { this._renderDecoMenu(); this._openDecoMenu(); });
    }

    document.addEventListener('click', (e) => {
      if (badgeSelect && !badgeSelect.contains(e.target)) this._closeBadgeMenu();
      if (iconSelect && !iconSelect.contains(e.target)) this._closeIconMenu();
      if (decoSelect && !decoSelect.contains(e.target)) this._closeDecoMenu();
    });
    this._modal.addEventListener('input', () => { this._dirty = true; });
    this._modal.addEventListener('change', () => { this._dirty = true; });
    this._wired = true;
  },

  // builds the visual icon/deco picker from assetsCache
  _buildPickers(iconKey, decoKey) {
    this._sel.icon = iconKey || 'star';
    this._sel.deco = decoKey || 'generic';
    const icons = (typeof assetsCache !== 'undefined' && assetsCache && assetsCache.icons) ? assetsCache.icons : {};
    const decos = (typeof assetsCache !== 'undefined' && assetsCache && assetsCache.decos) ? assetsCache.decos : {};

    this._iconList = Object.keys(icons);
    this._syncIconPreview();
    this._renderIconMenu();

    this._decoList = Object.keys(decos);
    const decoInput = document.getElementById('tmDeco');
    if (decoInput) decoInput.value = this._sel.deco;
    this._renderDecoMenu();
  },

  _setColor(color) {
    this._sel.color = color || '';
    document.querySelectorAll('#tmColorPresets .tm-preset').forEach(b =>
      b.classList.toggle('active', (b.dataset.color || '') === (color || '')));
    if (color) document.getElementById('tmColor').value = color;
    this._updatePreview();
  },

  // live character counters shown inside each textbox (title/description/category)
  _updateFieldCounters() {
    [['tmTitle', 'tmTitleCount', 'tmTitleCounter', 120],
     ['tmDesc',  'tmDescCount',  'tmDescCounter',  300],
     ['tmBadge', 'tmBadgeCount', 'tmBadgeCounter', 20]].forEach(([inputId, countId, wrapId, max]) => {
      const input = document.getElementById(inputId);
      const cEl   = document.getElementById(countId);
      const wrap  = document.getElementById(wrapId);
      if (!input) return;
      const len = input.value.length;
      if (cEl)  cEl.textContent = len.toLocaleString('en-US');
      if (wrap) wrap.classList.toggle('over', len >= max);
    });
  },

  // live preview of the card inside the modal
  _updatePreview() {
    const s = this._sel;
    document.getElementById('tmPrevTitle').textContent = document.getElementById('tmTitle').value || 'عنوان ابزار';
    document.getElementById('tmPrevDesc').textContent  = document.getElementById('tmDesc').value  || 'توضیح کوتاه';
    document.getElementById('tmPrevBadge').textContent = document.getElementById('tmBadge').value || 'ابزار';
    document.getElementById('tmPrevIcon').innerHTML    = makeSVG(s.icon || 'star', 20);
    const prev = document.getElementById('tmPreview');
    if (s.color) applyAccentColor(prev, s.color);
    else prev.style.cssText = '';
    const decoWrap = document.getElementById('tmPrevDeco');
    if (decoWrap && SVG_CACHE) {
      const node = SVG_CACHE.decoNodes[s.deco] || SVG_CACHE.decoNodes[DECO_FALLBACK];
      decoWrap.innerHTML = '';
      if (node) decoWrap.appendChild(node.cloneNode(true));
    }
  },

  _dirty: false,
  _isAdd: false,
  _unsaved: null,
  _showUnsaved() {
    if (!this._unsaved) this._unsaved = document.getElementById('toolUnsaved');
    if (!this._unsaved) return;
    const title = document.getElementById('tmUnsavedTitle');
    if (title) title.textContent = this._isAdd ? 'افزودن ابزار' : 'ویرایش ابزار';
    this._unsaved.classList.add('open'); this._unsaved.setAttribute('aria-hidden', 'false');
    const stayBtn    = document.getElementById('tmUnsavedStay');
    const discardBtn = document.getElementById('tmUnsavedDiscard');
    stayBtn.onclick    = () => this._hideUnsaved();
    discardBtn.onclick = () => { this._hideUnsaved(); this.closeModal(true); };
    this._unsaved.onclick = (e) => { if (e.target === this._unsaved) this._hideUnsaved(); };
  },
  _hideUnsaved() { if (this._unsaved) { this._unsaved.classList.remove('open'); this._unsaved.setAttribute('aria-hidden', 'true'); } },
  // While the edit/delete modal is open, decorative card animations are paused
  // (less lag + smoother) — same behavior as notif-modal-open for the notification detail modal.
  _syncModalState() {
    const active = !!((this._modal && this._modal.classList.contains('open')) ||
                       (this._confirm && this._confirm.classList.contains('open')));
    document.body.classList.toggle('tool-modal-open', active);
  },
  _show()      { this._modal.classList.add('open'); this._modal.setAttribute('aria-hidden', 'false'); this._syncModalState(); },
  closeModal(force) {
    if (!force && this._dirty) { this._showUnsaved(); return; }
    this._dirty = false;
    if (this._modal) { this._modal.classList.remove('open'); this._modal.setAttribute('aria-hidden', 'true'); }
    this._syncModalState();
  },

  // category dropdown: populated from categories already used by existing tools — you can
  // either click one of the existing ones or type a brand-new category directly in the box.
  // While typing, the list is filtered down to entries matching the typed text.
  _badgeList: [],
  _closeBadgeMenu() {
    const wrap = document.getElementById('tmBadgeSelect');
    if (wrap) wrap.classList.remove('open');
    const toggle = document.getElementById('tmBadgeToggle');
    if (toggle) toggle.setAttribute('aria-expanded', 'false');
  },
  _openBadgeMenu() {
    const wrap = document.getElementById('tmBadgeSelect');
    const menu = document.getElementById('tmBadgeMenu');
    if (!wrap || !menu || !menu.children.length) return;
    wrap.classList.add('open');
    const toggle = document.getElementById('tmBadgeToggle');
    if (toggle) toggle.setAttribute('aria-expanded', 'true');
  },
  // reads the existing categories from allToolsList and builds the menu (unfiltered).
  _updateBadgeList() {
    this._badgeList = [...new Set((allToolsList || []).map(t => t.badge).filter(Boolean))].sort();
    this._renderBadgeMenu();
  },
  // filters and renders the menu based on the box's current text.
  _renderBadgeMenu() {
    const menu  = document.getElementById('tmBadgeMenu');
    const input = document.getElementById('tmBadge');
    if (!menu) return;
    const rawQ  = ((input && input.value) || '').trim();
    const q     = rawQ.toLowerCase();
    const list  = (this._badgeList || []).filter(b => !q || b.toLowerCase().includes(q));
    menu.innerHTML = '';
    if (!list.length) {
      const empty = document.createElement('div');
      empty.className = 'tm-combo-empty';
      // this isn't an error state: typing a name not in the list means a new category will be created.
      empty.textContent = rawQ ? `دسته‌بندی «${rawQ}» به‌عنوان دسته جدید ثبت می‌شود` : 'دسته‌بندی‌ای ثبت نشده';
      menu.appendChild(empty);
      return;
    }
    list.forEach(b => {
      const opt = document.createElement('button');
      opt.type = 'button';
      opt.className = 'tm-combo-option' + (input && input.value === b ? ' selected' : '');
      opt.setAttribute('role', 'option');
      opt.textContent = b;
      // mousedown fires before click; preventDefault stops the box from blurring early,
      // so the click always registers the value correctly.
      opt.addEventListener('mousedown', (e) => e.preventDefault());
      opt.addEventListener('click', () => {
        if (input) input.value = b;
        this._closeBadgeMenu();
        this._updatePreview();
      });
      menu.appendChild(opt);
    });
  },

  // icon dropdown: search-only + selection from a fixed icon list; the selected
  // icon is previewed inside the box (next to the search input).
  _iconList: [],
  _syncIconPreview() {
    const input   = document.getElementById('tmIcon');
    const preview = document.getElementById('tmIconPreview');
    if (input) input.value = this._sel.icon || '';
    if (preview) preview.innerHTML = makeSVG(this._sel.icon || 'star', 17);
  },
  _closeIconMenu() {
    const wrap = document.getElementById('tmIconSelect');
    if (wrap) wrap.classList.remove('open');
    const toggle = document.getElementById('tmIconToggle');
    if (toggle) toggle.setAttribute('aria-expanded', 'false');
    this._syncIconPreview();
  },
  _openIconMenu() {
    const wrap = document.getElementById('tmIconSelect');
    const menu = document.getElementById('tmIconMenu');
    if (!wrap || !menu || !menu.children.length) return;
    wrap.classList.add('open');
    const toggle = document.getElementById('tmIconToggle');
    if (toggle) toggle.setAttribute('aria-expanded', 'true');
  },
  _renderIconMenu() {
    const menu  = document.getElementById('tmIconMenu');
    const input = document.getElementById('tmIcon');
    if (!menu) return;
    const q    = ((input && input.value) || '').trim().toLowerCase();
    const list = (this._iconList || []).filter(k => !q || k.toLowerCase().includes(q));
    menu.innerHTML = '';
    if (!list.length) {
      const empty = document.createElement('div');
      empty.className = 'tm-combo-empty';
      empty.textContent = 'آیکونی یافت نشد';
      menu.appendChild(empty);
      return;
    }
    list.forEach(k => {
      const opt = document.createElement('button');
      opt.type = 'button';
      opt.className = 'tm-combo-option tm-combo-option--icon' + (k === this._sel.icon ? ' selected' : '');
      opt.setAttribute('role', 'option');
      const ic = document.createElement('span');
      ic.className = 'tm-combo-option-ic';
      ic.innerHTML = makeSVG(k, 16);
      const label = document.createElement('span');
      label.textContent = k;
      opt.append(ic, label);
      opt.addEventListener('mousedown', (e) => e.preventDefault());
      opt.addEventListener('click', () => {
        this._sel.icon = k;
        this._closeIconMenu();
        this._updatePreview();
      });
      menu.appendChild(opt);
    });
  },

  // deco dropdown: search-only + selection from a fixed deco list (no way to
  // type a new value) — closing without a selection reverts the box to the selected value.
  _decoList: [],
  _closeDecoMenu() {
    const wrap = document.getElementById('tmDecoSelect');
    if (wrap) wrap.classList.remove('open');
    const toggle = document.getElementById('tmDecoToggle');
    if (toggle) toggle.setAttribute('aria-expanded', 'false');
    const input = document.getElementById('tmDeco');
    if (input) input.value = this._sel.deco || '';
  },
  _openDecoMenu() {
    const wrap = document.getElementById('tmDecoSelect');
    const menu = document.getElementById('tmDecoMenu');
    if (!wrap || !menu || !menu.children.length) return;
    wrap.classList.add('open');
    const toggle = document.getElementById('tmDecoToggle');
    if (toggle) toggle.setAttribute('aria-expanded', 'true');
  },
  _renderDecoMenu() {
    const menu  = document.getElementById('tmDecoMenu');
    const input = document.getElementById('tmDeco');
    if (!menu) return;
    const q    = ((input && input.value) || '').trim().toLowerCase();
    const list = (this._decoList || []).filter(k => !q || k.toLowerCase().includes(q));
    menu.innerHTML = '';
    if (!list.length) {
      const empty = document.createElement('div');
      empty.className = 'tm-combo-empty';
      empty.textContent = 'طرحی یافت نشد';
      menu.appendChild(empty);
      return;
    }
    list.forEach(k => {
      const opt = document.createElement('button');
      opt.type = 'button';
      opt.className = 'tm-combo-option' + (k === this._sel.deco ? ' selected' : '');
      opt.setAttribute('role', 'option');
      opt.textContent = k;
      opt.addEventListener('mousedown', (e) => e.preventDefault());
      opt.addEventListener('click', () => {
        this._sel.deco = k;
        this._closeDecoMenu();
        this._updatePreview();
      });
      menu.appendChild(opt);
    });
  },

  openAdd() {
    this._ensureWired(); if (!this._modal) return;
    this._isAdd = true;
    document.getElementById('tmHeadTitle').textContent = 'افزودن ابزار';
    document.getElementById('tmId').value = '';
    ['tmTitle', 'tmDesc', 'tmPath', 'tmBadge'].forEach(id => document.getElementById(id).value = '');
    document.getElementById('tmColor').value = '#3e7de7';
    this._updateBadgeList();
    this._buildPickers('star', 'generic');
    this._setColor('');
    this._updateFieldCounters();
    this._show();
    this._dirty = false;
    setTimeout(() => document.getElementById('tmTitle').focus(), 50);
  },

  openEdit(tool) {
    this._ensureWired(); if (!this._modal) return;
    this._isAdd = false;
    document.getElementById('tmHeadTitle').textContent = 'ویرایش ابزار';
    document.getElementById('tmId').value    = tool.id;
    document.getElementById('tmTitle').value = tool.title || '';
    document.getElementById('tmDesc').value  = tool.description || '';
    document.getElementById('tmPath').value  = tool.path || '';
    document.getElementById('tmBadge').value = tool.badge || '';
    document.getElementById('tmColor').value = tool.accentColor || '#3e7de7';
    this._updateBadgeList();
    this._buildPickers(tool.iconKey || 'star', tool.deco || 'generic');
    this._setColor(tool.accentColor || '');
    this._updateFieldCounters();
    this._show();
    this._dirty = false;
    setTimeout(() => document.getElementById('tmTitle').focus(), 50);
  },

  // marks the field + focuses it; always returns false so callers can `return this._failField(...)` directly.
  _failField(id, msg) {
    Toast.show(msg, 'error');
    const el = document.getElementById(id);
    if (el) el.focus();
    return false;
  },

  async save() {
    const id    = document.getElementById('tmId').value.trim();
    const title = document.getElementById('tmTitle').value.trim();
    const path  = document.getElementById('tmPath').value.trim();
    const badge = document.getElementById('tmBadge').value.trim();
    if (!title) return this._failField('tmTitle', 'عنوان الزامی است');
    if (!path)  return this._failField('tmPath', 'آدرس / مسیر الزامی است');
    if (!badge) return this._failField('tmBadge', 'انتخاب دسته‌بندی الزامی است');
    if (badge.length > 20 || !/^[\p{L}_]+$/u.test(badge)) {
      return this._failField('tmBadge', 'نام دسته‌بندی فقط می‌تواند شامل حروف و underscore باشد (حداکثر ۲۰ کاراکتر)');
    }
    const payload = {
      title,
      description: document.getElementById('tmDesc').value.trim(),
      path,
      badge,
      iconKey: this._sel.icon || 'star',
      deco:    this._sel.deco || 'generic',
      accentColor: this._sel.color || '',
    };
    if (id) payload.id = Number(id);
    const btn = document.getElementById('tmSave');
    btn.classList.add('loading'); btn.disabled = true;
    const data = await this.call(id ? 'edit' : 'add', payload);
    btn.classList.remove('loading'); btn.disabled = false;
    if (data && data.ok) {
      this.closeModal(true);
      await this.reload();
      Toast.show(id ? `${title} ویرایش شد` : `${title} ایجاد شد`, 'success', id ? 'ویرایش موفق' : 'افزودن موفق');
    } else {
      const FIELD_INPUT = { title: 'tmTitle', path: 'tmPath', badge: 'tmBadge' };
      const field = data && data.field && FIELD_INPUT[data.field];
      if (field) this._failField(field, (data && data.msg) || 'خطا در ذخیره');
      else Toast.show((data && data.msg) || 'خطا در ذخیره', 'error');
    }
  },

  askDelete(id, title) {
    this._ensureWired(); if (!this._confirm) return;
    this._delId = id;
    this._delName = title || '';
    // safe DOM construction instead of an innerHTML string: the tool title (user data) is
    // inserted via textContent so that even if it contains HTML, it won't execute/render (anti-XSS).
    const el = document.getElementById('tmConfirmDesc');
    const name = document.createElement('span');
    name.className = 'item-name';
    name.textContent = title || '';
    el.textContent = 'ابزار ';
    el.appendChild(name);
    el.appendChild(document.createTextNode(' به‌طور دائم حذف خواهد شد.'));
    this._confirm.classList.add('open'); this._confirm.setAttribute('aria-hidden', 'false');
    this._syncModalState();
  },
  _hideConfirm() { if (this._confirm) { this._confirm.classList.remove('open'); this._confirm.setAttribute('aria-hidden', 'true'); } this._delId = null; this._syncModalState(); },
  async doDelete() {
    if (!this._delId) return;
    const delName = this._delName || 'ابزار';
    const ok = document.getElementById('tmConfirmOk'); ok.disabled = true;
    const data = await this.call('delete', { id: this._delId });
    ok.disabled = false; this._hideConfirm();
    if (data && data.ok) { await this.reload(); Toast.show(`${delName} حذف شد`, 'success', 'حذف موفق'); }
    else Toast.show((data && data.msg) || 'خطا در حذف ابزار', 'error');
  },

  async reload() {
    try {
      const res = await fetch(API_URL + '?action=tools', { headers: { 'Accept': 'application/json' }, cache: 'no-store' });
      const data = await res.json();
      if (data.ok && Array.isArray(data.tools)) {
        allToolsList = data.tools;
        buildFilterChips();
        renderTools(searchInput ? searchInput.value : '');
      }
    } catch (_) {}
  },

  // ── card reordering (drag-drop) ─────────────────────────
  initReorder() {
    if (this._reorderWired) return;
    const toggle = document.getElementById('reorderToggle');
    if (!toggle) return;            // only when the server has rendered admin controls
    this._reorderWired = true;
    toggle.addEventListener('click', () => this._reordering ? this.exitReorder() : this.enterReorder());
    document.getElementById('reorderCancel')?.addEventListener('click', () => this.exitReorder());
    document.getElementById('reorderSave')?.addEventListener('click', () => this.saveReorder());
    this._initDrag();
  },

  enterReorder() {
    if (!Array.isArray(allToolsList) || allToolsList.length < 2 || !grid) return;
    this._reordering = true;
    document.getElementById('reorderToggle')?.classList.add('is-active');
    const bar = document.getElementById('reorderBar'); if (bar) bar.hidden = false;
    if (typeof cardVisibilityObserver !== 'undefined') cardVisibilityObserver?.disconnect();
    if (typeof loadMoreObserver !== 'undefined') loadMoreObserver?.disconnect();
    // render all cards at once (no lazy loading), in one column, so the full order is in the DOM
    grid.textContent = '';
    grid.classList.add('reordering');
    const frag = document.createDocumentFragment();
    allToolsList.forEach(t => {
      const c = createCard(t);
      c.setAttribute('draggable', 'true');
      frag.appendChild(c);
    });
    grid.appendChild(frag);
    // stop SMIL animations (<animate>/<animateMotion>/<animateTransform>) — these aren't
    // stopped by CSS `animation-play-state: paused`, so we pause the SVG timeline directly.
    grid.querySelectorAll('svg').forEach(s => { try { s.pauseAnimations(); } catch (_) {} });
    window.scrollTo({ top: 0, behavior: 'smooth' });
  },

  exitReorder() {
    this._reordering = false;
    this._stopScroll();
    document.getElementById('reorderToggle')?.classList.remove('is-active');
    const bar = document.getElementById('reorderBar'); if (bar) bar.hidden = true;
    grid.classList.remove('reordering');
    renderTools(searchInput ? searchInput.value : '');   // back to the normal view
  },

  async saveReorder() {
    const ids = [...grid.querySelectorAll('.card[data-tool-id]')].map(c => Number(c.dataset.toolId));
    const btn = document.getElementById('reorderSave');
    if (btn) { btn.classList.add('loading'); btn.disabled = true; }
    const data = await this.call('reorder', { ids });
    if (data && data.ok) { location.reload(); return; }
    if (btn) { btn.classList.remove('loading'); btn.disabled = false; }
    const msg = document.querySelector('.reorder-bar-msg');
    if (msg) msg.textContent = (data && data.msg) || 'خطا در ذخیره ترتیب';
  },

  // placeholder = the destination slot (same size as a card) shown during a drag
  _makePlaceholder(card) {
    const h = card.getBoundingClientRect().height;
    const ph = document.createElement('div');
    ph.className = 'card-drop-slot';
    ph.style.minHeight = Math.round(h) + 'px';
    return ph;
  },

  // Only moves the placeholder when the pointer is exactly over another card.
  // In the gap/space between cards it does nothing → eliminates edge jitter; and since the
  // placeholder settles right under the pointer after each move, further calls become no-ops (stable).
  _movePlaceholder(x, y) {
    if (!this._ph) return;
    const under = document.elementFromPoint(x, y);
    const overCard = under && under.closest ? under.closest('.card[data-tool-id]') : null;
    if (!overCard || overCard === this._dragCard) return;   // gap or the drag source → don't move
    const r = overCard.getBoundingClientRect();
    const cx = r.left + r.width / 2, cy = r.top + r.height / 2;
    const sameRow = Math.abs(y - cy) < r.height / 2;
    const before = sameRow ? (x > cx) : (y < cy);   // RTL: right side = ahead
    const ref = before ? overCard : overCard.nextSibling;
    if (ref === this._ph) return;
    if (this._ph.nextSibling === ref) return;       // already there → don't move
    grid.insertBefore(this._ph, ref);
  },

  _finishDrag() {
    this._stopScroll();
    document.body.classList.remove('is-dragging');
    if (this._dragCard) {
      if (this._ph && this._ph.parentNode) grid.insertBefore(this._dragCard, this._ph);
      this._dragCard.classList.remove('card--dragging');
    }
    if (this._ph && this._ph.parentNode) this._ph.remove();
    if (this._clone) { this._clone.remove(); this._clone = null; }
    this._ph = null; this._dragCard = null;
  },

  // edge auto-scroll: when the pointer/finger nears the top or bottom of the screen,
  // the page scrolls automatically so a card can be dragged to rows outside the viewport.
  // (Normal scrolling doesn't work during a drag; this replaces it — desktop + touch.)
  _autoScroll(x, y) {
    this._lastX = x; this._lastY = y;
    const EDGE = 96, vh = window.innerHeight;
    let dir = 0, intensity = 0;
    if (y < EDGE)           { dir = -1; intensity = (EDGE - y) / EDGE; }
    else if (y > vh - EDGE) { dir = 1;  intensity = (y - (vh - EDGE)) / EDGE; }
    this._scrollDir = dir;
    this._scrollSpeed = dir ? Math.max(6, Math.round(Math.min(1, intensity) * 22)) : 0;
    if (dir && this._scrollRAF == null) this._scrollStep();
    else if (!dir) this._stopScroll();
  },
  _scrollStep() {
    if (!this._scrollDir) { this._scrollRAF = null; return; }
    window.scrollBy(0, this._scrollDir * this._scrollSpeed);
    this._movePlaceholder(this._lastX, this._lastY);   // content under the pointer changed → update
    this._scrollRAF = requestAnimationFrame(() => this._scrollStep());
  },
  _stopScroll() {
    this._scrollDir = 0;
    if (this._scrollRAF != null) { cancelAnimationFrame(this._scrollRAF); this._scrollRAF = null; }
  },

  _initDrag() {
    if (this._dragWired || !grid) return;
    this._dragWired = true;
    const active = () => this._reordering;

    // ── desktop: HTML5 Drag & Drop (hidden source + destination placeholder) ──
    grid.addEventListener('dragstart', (e) => {
      if (!active()) { e.preventDefault(); return; }
      const card = e.target.closest('.card');
      if (!card) return;
      this._dragCard = card;
      if (e.dataTransfer) { e.dataTransfer.effectAllowed = 'move'; try { e.dataTransfer.setData('text/plain', card.dataset.toolId || ''); } catch (_) {} }
      // the drag image snapshot is captured right now; the source is hidden and the placeholder
      // inserted on the next tick
      setTimeout(() => {
        if (!this._dragCard) return;
        this._ph = this._makePlaceholder(card);
        grid.insertBefore(this._ph, card);
        card.classList.add('card--dragging');
        document.body.classList.add('is-dragging');   // pause all moving animations during the drag
      }, 0);
    });
    grid.addEventListener('dragover', (e) => {
      if (!active() || !this._dragCard) return;
      e.preventDefault();
      if (e.dataTransfer) e.dataTransfer.dropEffect = 'move';
      this._movePlaceholder(e.clientX, e.clientY);
      this._autoScroll(e.clientX, e.clientY);
    });
    grid.addEventListener('drop', (e) => { if (active()) { e.preventDefault(); this._finishDrag(); } });
    grid.addEventListener('dragend', () => { if (this._dragCard) this._finishDrag(); });
    // card navigation clicks are disabled while in reorder mode
    grid.addEventListener('click', (e) => { if (active()) { e.preventDefault(); e.stopPropagation(); } }, true);

    // ── touch (mobile): floating clone of the source + the same destination placeholder ──
    let tOffX = 0, tOffY = 0;
    grid.addEventListener('touchstart', (e) => {
      if (!active()) return;
      const card = e.target.closest('.card');
      if (!card) return;
      this._dragCard = card;
      const r = card.getBoundingClientRect();
      tOffX = e.touches[0].clientX - r.left;
      tOffY = e.touches[0].clientY - r.top;
      this._clone = card.cloneNode(true);
      Object.assign(this._clone.style, {
        position: 'fixed', zIndex: '999', left: r.left + 'px', top: r.top + 'px',
        width: r.width + 'px', margin: '0', opacity: '.9', pointerEvents: 'none',
        boxShadow: '0 12px 30px rgba(15,23,42,.35)', borderRadius: 'var(--radius-lg)',
      });
      document.body.appendChild(this._clone);
      this._clone.querySelectorAll('svg').forEach(s => { try { s.pauseAnimations(); } catch (_) {} }); // pause the clone's SMIL too
      this._ph = this._makePlaceholder(card);
      grid.insertBefore(this._ph, card);
      card.classList.add('card--dragging');
      document.body.classList.add('is-dragging');   // pause all moving animations during the drag
    }, { passive: true });
    grid.addEventListener('touchmove', (e) => {
      if (!this._dragCard || !this._clone) return;
      e.preventDefault();
      const x = e.touches[0].clientX, y = e.touches[0].clientY;
      this._clone.style.left = (x - tOffX) + 'px';
      this._clone.style.top  = (y - tOffY) + 'px';
      this._movePlaceholder(x, y);
      this._autoScroll(x, y);
    }, { passive: false });
    const endTouch = () => { if (this._dragCard) this._finishDrag(); };
    grid.addEventListener('touchend', endTouch);
    grid.addEventListener('touchcancel', endTouch);
  },
};
window.AdminTools = AdminTools;

function boot() {
  document.readyState === 'loading'
    ? document.addEventListener('DOMContentLoaded', init)
    : init();
  // admin reorder controls (only when the server has rendered them)
  AdminTools.initReorder();
}

// If this page was prerendered, defer init until "activation" (actual display); otherwise
// the data could be fetched with a stale pre-auth session at prerender time, and after
// logging in that stale state would keep showing until a refresh. Now it loads with the
// current (post-login) session instead.
if (document.prerendering) {
  document.addEventListener('prerenderingchange', boot, { once: true });
} else {
  boot();
}

/* ── actions (replaces on* for CSP) ── */
if (window.Actions) {
  Actions.register({
    tmHideUnsaved: () => AdminTools._hideUnsaved(),
  });
}