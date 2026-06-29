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

/* ── نمایش/مخفی کردن رمز (مودال ورود) ── */
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
   رنگ سفارشی
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

/* ═══════════════════════════════════════════════════════════
   sanitizeNotifHtml — پاک‌سازی HTML متن اعلان (دفاع لایه دوم)
   فقط تگ‌ها و ویژگی‌های امن مجازند؛ بقیه حذف می‌شوند.
   ═══════════════════════════════════════════════════════════ */
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
        child.remove();
      }
    });
  };
  walk(tpl.content);
  return tpl.innerHTML;
}

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

      const display   = this.displayName || this.username || '';
      const firstChar = display ? [...display][0] : '؟';

      const avatar = document.getElementById('userMenuAvatar');
      const name   = document.getElementById('userMenuName');
      const dName  = document.getElementById('dropdownDisplayName');
      const dUname = document.getElementById('dropdownUsername');

      if (avatar) avatar.textContent = firstChar;
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

    // کنترل‌های مرتب‌سازی ادمین (سرور-رندر) را با وضعیت فعلی ادمین همگام کن —
    // در خروج ادمین بدون رفرش، دکمه «مرتب‌سازی»/نوار نباید باقی بماند.
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
   Notification Detail Modal
   ═══════════════════════════════════════════════════════════ */
const NotifDetail = {
  open(n) {
    const modal = document.getElementById('notifDetailModal');
    if (!modal) return;

    // عنوان
    document.getElementById('ndTitle').textContent = n.title || '';

    // تصویر — بارگذاری پیشرونده (thumbnail → full)
    const imgWrap = document.getElementById('ndImageWrap');
    const img     = document.getElementById('ndImage');
    if (n.image_path) {
      imgWrap.style.display = 'block';
      imgWrap.classList.add('img-loading');
      img.alt           = n.title || '';
      img.style.cssText = '';
      img.dataset.full  = n.image_path;   // مبنای نمایش تمام‌صفحه (lightbox)

      if (n.thumbnail_path) {
        // thumbnail موجود: فوری نشان بده (blurred) — shimmer پشتش پیداست
        img.src             = n.thumbnail_path;
        img.style.filter    = 'blur(10px)';
        img.style.transform = 'scale(1.04)';
      } else {
        // بدون thumbnail: img مخفی — shimmer دیده می‌شود
        img.src             = '';
        img.style.display   = 'none';
      }

      // لود تصویر اصلی در پس‌زمینه
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

    // متن (HTML غنی — پاک‌سازی‌شده در سمت سرور، دوباره در سمت کلاینت)
    const bodyEl = document.getElementById('ndBody');
    if (n.body) {
      bodyEl.innerHTML     = sanitizeNotifHtml(n.body);
      bodyEl.style.display = 'block';
    } else {
      bodyEl.style.display = 'none';
      bodyEl.innerHTML     = '';
    }

    // تاریخ
    const dateEl = document.getElementById('ndDate');
    dateEl.textContent = n.created_at
      ? new Date(n.created_at).toLocaleString('fa-IR')
      : '';

    // انقضا
    const expiryEl = document.getElementById('ndExpiry');
    if (n.expires_at) {
      const d = new Date(n.expires_at * 1000);
      expiryEl.textContent = `انقضا: ${d.toLocaleString('fa-IR')}`;
      expiryEl.style.display = 'block';
    } else {
      expiryEl.style.display = 'none';
    }

    // لینک «مشاهده همه» — فقط برای لاگین‌شده‌ها
    const allLink = document.getElementById('ndViewAllLink');
    if (allLink) allLink.style.display = Auth.loggedIn ? 'inline-flex' : 'none';

    modal.classList.add('open');
    document.body.style.overflow = 'hidden';
    // توقف انیمیشن‌های پس‌زمینه تا backdrop-blur هر فریم بازمحاسبه نشود
    document.body.classList.add('notif-modal-open');
  },

  close() {
    const modal = document.getElementById('notifDetailModal');
    if (!modal) return;
    modal.classList.remove('open');
    document.body.style.overflow = '';
    document.body.classList.remove('notif-modal-open');
    // پاکسازی state بارگذاری پیشرونده
    const img     = document.getElementById('ndImage');
    const imgWrap = document.getElementById('ndImageWrap');
    if (img)     { img.src = ''; img.style.cssText = ''; delete img.dataset.full; }
    if (imgWrap) imgWrap.classList.remove('img-loading');
  },
};

/* ═══════════════════════════════════════════════════════════
   Notification Panel
   ═══════════════════════════════════════════════════════════ */
const NotifPanel = {
  _open:          false,
  _notifications: [],
  _unreadCount:   0,
  _page:          1,
  _PER_PAGE:      6,
  _pollTimer:     null,
  _POLL_MS:       25000,   // فاصله poll: هر ۲۵ ثانیه
  _loaded:        false,   // آیا لیست کامل (لود تنبل) آمده است؟
  _loading:       false,   // گارد ضد فراخوانی هم‌زمان

  async load() {
    if (this._loading) return;          // جلوگیری از فراخوانی هم‌زمان دوگانه
    this._loading = true;
    try {
      const [nRes, cRes] = await Promise.all([
        fetch(`${API_URL}?action=notifications`, { cache: 'no-cache' }),
        fetch(`${API_URL}?action=unread_count`,  { cache: 'no-cache' }),
      ]);
      const [nData, cData] = await Promise.all([nRes.json(), cRes.json()]);
      if (nData.ok) this._notifications = nData.notifications || [];
      this._loaded = true;

      if (Auth.loggedIn) {
        if (cData.ok) this._unreadCount = cData.count || 0;
        this._updateBadge();
      } else {
        // برای مهمان شمارش از روی localStorage محاسبه می‌شود
        this._applyGuestReadState();
      }
      // اگر پنل هنگام لود پس‌زمینه باز بود، حالا با داده واقعی رندر کن
      if (this._open) this._renderDropdown();
    } catch {
      // در خطا، state قبلی را پاک نکن (ممکن است از لود قبلی معتبر باشد)
      this._updateBadge();
    } finally {
      this._loading = false;
    }
  },

  reset() {
    this._notifications = [];
    this._unreadCount   = 0;
    this._page          = 1;
    this._loaded        = false;   // تا در ورود/خروج بعدی دوباره لود شود
    this._updateBadge();
    this.close();
  },

  // ── Polling بلادرنگ ──────────────────────────────────────
  startPolling() {
    this.stopPolling();
    this._pollTimer = setInterval(() => {
      if (!document.hidden) this._poll();
    }, this._POLL_MS);
  },

  stopPolling() {
    if (this._pollTimer) { clearInterval(this._pollTimer); this._pollTimer = null; }
  },

  async _poll() {
    try {
      if (Auth.loggedIn) {
        // فقط شمارش را می‌گیریم (سبک)؛ اگر تغییر کرد، لیست را تازه می‌کنیم
        const res  = await fetch(`${API_URL}?action=unread_count`, { cache: 'no-cache' });
        const data = await res.json();
        if (!data.ok) return;
        const newCount = data.count || 0;
        if (newCount !== this._unreadCount) {
          await this.load();                 // لیست + badge را هماهنگ می‌کند
          if (this._open) this._renderDropdown();
        }
      } else {
        // مهمان: لیست را می‌گیریم و unread را از localStorage محاسبه می‌کنیم
        const res  = await fetch(`${API_URL}?action=notifications`, { cache: 'no-cache' });
        const data = await res.json();
        if (!data.ok) return;
        this._notifications = data.notifications || [];
        this._applyGuestReadState();         // شمارش + badge
        if (this._open) this._renderDropdown();
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
    // اگر لیست تنبل هنوز نیامده، همین حالا بیاور (load خودش بعد آمدن رندر می‌کند)
    if (!this._loaded) this.load();
    const btn      = document.getElementById('notifBellBtn');
    const dropdown = document.getElementById('notifDropdown');
    if (btn)      btn.setAttribute('aria-expanded', 'true');
    if (dropdown) {
      this._renderDropdown();
      dropdown.classList.add('open');
      dropdown.setAttribute('aria-hidden', 'false');
    }
    UserMenu.close();
  },

  close() {
    this._open = false;
    const btn      = document.getElementById('notifBellBtn');
    const dropdown = document.getElementById('notifDropdown');
    if (btn)      btn.setAttribute('aria-expanded', 'false');
    if (dropdown) { dropdown.classList.remove('open'); dropdown.setAttribute('aria-hidden', 'true'); }
  },

  _renderDropdown() {
    const body = document.getElementById('notifDropdownBody');
    if (!body) return;

    const total = this._notifications.length;
    const pages = Math.max(1, Math.ceil(total / this._PER_PAGE));
    this._page  = Math.min(Math.max(1, this._page), pages);

    const start = (this._page - 1) * this._PER_PAGE;
    const list  = this._notifications.slice(start, start + this._PER_PAGE);

    // ── صفحه‌بندی ───────────────────────────────────────
    const pagWrap  = document.getElementById('notifPagination');
    const prevBtn  = document.getElementById('notifPrevBtn');
    const nextBtn  = document.getElementById('notifNextBtn');
    const pageInfo = document.getElementById('notifPageInfo');
    if (pagWrap) {
      pagWrap.style.display   = pages > 1 ? 'flex' : 'none';
      if (prevBtn)  prevBtn.disabled  = this._page <= 1;
      if (nextBtn)  nextBtn.disabled  = this._page >= pages;
      if (pageInfo) pageInfo.textContent = pages > 1 ? `${this._page} / ${pages}` : '';
    }

    if (!list.length) {
      body.innerHTML = `
        <div class="notif-drop-empty">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
            <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
            <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
          </svg>
          <p>اعلانی برای نمایش وجود ندارد</p>
        </div>`;
      return;
    }

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
        if (Auth.loggedIn && !n.is_read) {
          this._markReadSilent(n.id, item, n);
        } else if (!Auth.loggedIn && !n.is_read) {
          this._markReadGuest(n.id, n);
        }
      };

      const viewBtn = item.querySelector('.notif-drop-view-btn');
      viewBtn.addEventListener('click', e => { e.stopPropagation(); openDetail(); });
      item.addEventListener('click', openDetail);
      item.addEventListener('keydown', e => {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openDetail(); }
      });
      item.setAttribute('tabindex', '0');

      frag.appendChild(item);
    });

    body.appendChild(frag);
  },

  prevPage() {
    if (this._page > 1) { this._page--; this._renderDropdown(); }
  },

  nextPage() {
    const pages = Math.ceil(this._notifications.length / this._PER_PAGE);
    if (this._page < pages) { this._page++; this._renderDropdown(); }
  },

  // mark read بدون بستن modal یا تغییر UI dropdown (که بسته شده)
  async _markReadSilent(id, itemEl, notifObj) {
    // به‌روزرسانی state محلی
    if (notifObj) notifObj.is_read = true;
    this._unreadCount = Math.max(0, this._unreadCount - 1);

    // اعلان منقضی‌شده پس از خوانده‌شدن از لیست فعال حذف می‌شود
    // (مطابق رفتار سرور در allActiveForUser) تا بدون رفرش صفحه از لیست برود
    if (notifObj && notifObj.is_expired) {
      this._notifications = this._notifications.filter(x => x.id !== id);
    }

    this._updateBadge();
    if (this._open) this._renderDropdown();

    // API در پس‌زمینه
    try {
      await fetch(`${API_URL}?action=mark_read`, {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({ notification_id: id }),
      });
    } catch { /* silent */ }
  },

  // mark read برای مهمان — localStorage با زمان خواندن (پشتیبانی از rebadge هنگام ویرایش)
  _markReadGuest(id, notifObj) {
    if (notifObj && notifObj.is_read) return;
    if (notifObj) notifObj.is_read = true;
    this._unreadCount = Math.max(0, this._unreadCount - 1);

    // اعلان منقضی‌شده پس از خوانده‌شدن از لیست فعال حذف می‌شود
    if (notifObj && notifObj.is_expired) {
      this._notifications = this._notifications.filter(x => x.id !== id);
    }

    this._updateBadge();
    if (this._open) this._renderDropdown();

    try {
      const map = this._getGuestReadMap();
      map[id] = Math.floor(Date.now() / 1000);   // زمان خواندن (ثانیه)
      this._setGuestReadMap(map);
    } catch { /* silent */ }
  },

  // خواندن نگاشت {id: read_ts} با سازگاری با فرمت قدیمی (آرایه id)
  _getGuestReadMap() {
    try {
      const raw = localStorage.getItem('notif_read_ids');
      if (!raw) return {};
      const parsed = JSON.parse(raw);
      if (Array.isArray(parsed)) {
        // مهاجرت از فرمت قدیمی: id خوانده‌شده با زمان ۰ (همیشه read مگر ویرایش جدید)
        const map = {};
        parsed.forEach(id => { map[id] = 0; });
        return map;
      }
      return (parsed && typeof parsed === 'object') ? parsed : {};
    } catch { return {}; }
  },

  _setGuestReadMap(map) {
    try {
      // فقط ۸۰ آیدی آخر را نگه می‌داریم تا localStorage پر نشود
      let entries = Object.entries(map);
      if (entries.length > 80) entries = entries.slice(entries.length - 80);
      localStorage.setItem('notif_read_ids', JSON.stringify(Object.fromEntries(entries)));
    } catch { /* silent */ }
  },

  // اعمال وضعیت خوانده‌شده مهمان + محاسبه شمارش (همیشه)
  _applyGuestReadState() {
    try {
      const map = this._getGuestReadMap();
      this._notifications.forEach(n => {
        const readTs   = map[n.id];
        const updatedTs = n.updated_at ? Math.floor(new Date(n.updated_at).getTime() / 1000) : 0;
        // خوانده‌شده فقط وقتی که بعد از آخرین ویرایش خوانده شده باشد
        n.is_read = (readTs !== undefined) && (readTs === 0 || readTs >= updatedTs);
      });
      // اعلان‌های منقضی‌شده‌ای که قبلا خوانده شده‌اند از لیست حذف می‌شوند
      // (منقضی‌شده‌های ناخوانده باقی می‌مانند تا badge را زنده نگه دارند)
      this._notifications = this._notifications.filter(n => !(n.is_expired && n.is_read));
      this._unreadCount = this._notifications.filter(n => !n.is_read).length;
      this._updateBadge();
    } catch { /* silent */ }
  },

  async markAllRead() {
    if (!Auth.loggedIn) return;
    this._notifications.forEach(n => { n.is_read = true; });
    this._unreadCount = 0;
    this._updateBadge();
    this._renderDropdown();
    try {
      await fetch(`${API_URL}?action=mark_all_read`, { method: 'POST' });
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
    return new Date(dateStr).toLocaleDateString('fa-IR');
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

/* ═══════════════════════════════════════════════════════════
   Card visibility observer — pause انیمیشن کارت‌های off-screen
   ═══════════════════════════════════════════════════════════ */
let cardVisibilityObserver = null;
function getCardVisibilityObserver() {
  if (cardVisibilityObserver) return cardVisibilityObserver;
  if (typeof IntersectionObserver === 'undefined') return null;
  cardVisibilityObserver = new IntersectionObserver(entries => {
    for (const entry of entries) {
      entry.target.classList.toggle('card--offscreen', !entry.isIntersecting);
    }
  }, { rootMargin: '300px 0px', threshold: 0 });
  return cardVisibilityObserver;
}

/* ═══════════════════════════════════════════════════════════
   Render
   ═══════════════════════════════════════════════════════════ */
/* ── لود تدریجی (lazy): کارت‌ها در دسته‌های BATCH_SIZE ساخته می‌شوند و
      دسته بعدی فقط وقتی کاربر به انتهای لیست نزدیک شد رندر می‌شود.
      این کار از ساخت یکجای صدها کارت + انیمیشن deco جلوگیری می‌کند. */
const BATCH_SIZE = 12;
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

  // observe فقط کارت‌های تازه برای pause انیمیشن off-screen
  const obs = getCardVisibilityObserver();
  if (obs) newCards.forEach(c => obs.observe(c));

  // اگر هنوز کارتی مانده، sentinel بساز و رصد کن تا دسته بعد لود شود
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
      renderNextBatch(); // بدون IntersectionObserver: همه را یکجا بساز
    }
  }
}

function renderTools(filterText = '') {
  // disconnect observerها پیش از پاک کردن DOM (جلوگیری از memory leak)
  cardVisibilityObserver?.disconnect();
  loadMoreObserver?.disconnect();

  grid.textContent = '';

  // تایل ثابت «افزودن ابزار» — همیشه اول گرید برای ادمین
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
  decoWrap.appendChild(decoNode.cloneNode(true));

  const arrow = document.createElement('div');
  arrow.className = 'card-arrow';
  arrow.appendChild(SVG_CACHE.arrowSVG.cloneNode(true));

  card.append(cornerWrap, iconEl, badge, title, desc, decoWrap, arrow);

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

  // کنترل‌های مدیریت اینلاین (فقط وقتی ادمین وارد است)
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

// ── حالت جستجو (سبک تلگرام): آیکون #searchToggle نوار جستجوی تمام‌عرض را باز می‌کند ──
const appHeader    = document.querySelector('.app-header');
const searchToggle = document.getElementById('searchToggle');
const searchClose  = document.getElementById('searchClose');
function openSearch()  { if (appHeader) appHeader.classList.add('searching'); searchInput.focus(); }
function closeSearch() {
  if (appHeader) appHeader.classList.remove('searching');
  if (searchInput.value) { searchInput.value = ''; handleSearch(''); }
  searchInput.blur();
}
if (searchToggle) searchToggle.addEventListener('click', openSearch);
if (searchClose)  searchClose.addEventListener('click', closeSearch);

searchInput.addEventListener('keydown', e => {
  if (e.key === 'Escape') closeSearch(); // Esc → بستن نوار (و پاک‌کردن متن در صورت وجود)
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

  // ── زنگ اعلان ──────────────────────────────────────────
  const bellBtn = e.target.closest('#notifBellBtn');
  if (bellBtn) {
    e.stopPropagation();
    NotifPanel.toggle();
    return;
  }

  // ── صفحه‌بندی dropdown اعلان‌ها ───────────────────────
  const notifPrev = e.target.closest('#notifPrevBtn');
  if (notifPrev) { e.stopPropagation(); NotifPanel.prevPage(); return; }
  const notifNext = e.target.closest('#notifNextBtn');
  if (notifNext) { e.stopPropagation(); NotifPanel.nextPage(); return; }

  // ── بستن modal جزئیات ─────────────────────────────────
  const detailClose = e.target.closest('#notifDetailClose');
  if (detailClose) { NotifDetail.close(); return; }
  const detailOverlay = document.getElementById('notifDetailModal');
  if (detailOverlay && e.target === detailOverlay) { NotifDetail.close(); return; }

  // ── کلیک بیرون از پنل اعلان — بستن ──────────────────
  const notifWrap = e.target.closest('#notifBellWrap');
  if (!notifWrap && NotifPanel._open) {
    NotifPanel.close();
  }

  // ── منوی کاربر ─────────────────────────────────────────
  const menuBtn = e.target.closest('#userMenuBtn');
  if (menuBtn) {
    e.stopPropagation();
    NotifPanel.close();
    UserMenu.toggle();
    return;
  }

  // دکمه «ورود» اکنون لینک مستقیم به login.php است (نیازی به JS ندارد)

  // ── دکمه خروج ──────────────────────────────────────────
  const logoutBtn = e.target.closest('#logoutBtn');
  if (logoutBtn) {
    UserMenu.close();
    handleLogout();
    return;
  }

  // ── کلیک بیرون از منوی کاربر ─────────────────────────
  const menuWrap = e.target.closest('#userMenuWrap');
  if (!menuWrap) UserMenu.close();
});

/* ═══════════════════════════════════════════════════════════
   Keyboard: Escape
   ═══════════════════════════════════════════════════════════ */
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') {
    // اولویت: modal جزئیات > پنل اعلان > منوی کاربر > login modal
    const detailModal = document.getElementById('notifDetailModal');
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
  await fetch(`${API_URL}?action=logout`, { method: 'POST' });
  Auth.setLoggedOut();
  NotifPanel.reset();
  await loadData();
  await NotifPanel.load();
}

/* ورود اکنون در صفحه مجزای login.php انجام می‌شود (به‌جای مودال). */

/* ═══════════════════════════════════════════════════════════
   Skeleton
   ═══════════════════════════════════════════════════════════ */
const SKELETON_CARD =
  '<div class="skeleton-card" aria-hidden="true">'
  + '<div class="sk sk-icon"></div><div class="sk sk-badge"></div>'
  + '<div class="sk sk-title"></div><div class="sk sk-line"></div>'
  + '<div class="sk sk-line sk-line--short"></div></div>';

function showSkeleton(n = 6) {
  // قبل از پاک‌کردن DOM، observerها را قطع کن (جلوگیری از نشتی حافظه)
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
   بهینه برای همزمانی بالا: همه داده اولیه (وضعیت لاگین، assets،
   ابزارها، اعلان‌ها، شمارش) در «یک» درخواست bootstrap گرفته می‌شود
   تا به‌جای ۵ رفت‌وبرگشت شبکه، فقط ۱ اتصال باز شود.
   اگر bootstrap در دسترس نبود (نسخه قدیمی سرور)، به روش چند-درخواستی
   برمی‌گردد.
   ═══════════════════════════════════════════════════════════ */
async function init() {
  showSkeleton();
  try {
    let data = null;
    try {
      const res = await fetch(`${API_URL}?action=bootstrap`, { cache: 'no-cache' });
      if (res.ok) data = await res.json();
    } catch { /* به fallback می‌رویم */ }

    if (data && data.ok) {
      applyBootstrap(data);
    } else {
      await initLegacy();   // fallback: چند درخواست جداگانه
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

/* اعمال خروجی bootstrap روی state */
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

  // اعلان‌ها دیگر در bootstrap حمل نمی‌شوند (تا کارت‌ها منتظر ~۱۰۵KB نمانند).
  // فقط شمارش اولیه (کاربر لاگین‌شده) ست می‌شود تا بج فوری ظاهر شود؛
  // لیست کامل در startRealtime() به‌صورت پس‌زمینه لود می‌شود.
  NotifPanel._unreadCount = (data.unread && data.unread.ok) ? (data.unread.count || 0) : 0;
  NotifPanel._updateBadge();

  startRealtime();
}

/* روش قدیمی (fallback): چند درخواست موازی */
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

    if (notifData.ok) NotifPanel._notifications = notifData.notifications || [];
    if (countData.ok) NotifPanel._unreadCount   = countData.count         || 0;
    if (!meData.logged_in) NotifPanel._applyGuestReadState();
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

/* شروع poll بلادرنگ + چک فوری هنگام برگشت به تب */
function startRealtime() {
  // لود غیرمسدودکننده لیست اعلان‌ها بعد از رندر کارت‌ها (دیگر بخشی از bootstrap نیست)
  NotifPanel.load();
  NotifPanel.startPolling();
  document.addEventListener('visibilitychange', () => {
    if (!document.hidden) NotifPanel._poll();
  });
  window.addEventListener('focus', () => NotifPanel._poll());
}

/* ═══════════════════════════════════════════════════════════
   مدیریت اینلاین ابزارها برای ادمین (روی همین داشبورد)
   فعال فقط وقتی کاربر ادمین وارد است و CSRF در دسترس است.
   نوشتن به admin.php?api=add|edit|delete|toggle_public (role از DB چک می‌شود).
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
    pub:  '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 9.9-1"/></svg>',
    prv:  '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>',
    lockSm: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>',
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
    if (!tool.is_public) card.classList.add('card--private');

    const bar = document.createElement('div');
    bar.className = 'card-admin-bar';

    const tgl = document.createElement('button');
    tgl.type = 'button';
    tgl.className = 'cab-btn cab-toggle' + (tool.is_public ? ' is-public' : '');
    tgl.title = tool.is_public ? 'عمومی — کلیک: خصوصی شود' : 'خصوصی — کلیک: عمومی شود';
    tgl.innerHTML = tool.is_public ? this._ic.pub : this._ic.prv;
    tgl.addEventListener('click', (e) => { e.stopPropagation(); this.toggle(tool.id, card, tgl); });

    const ed = document.createElement('button');
    ed.type = 'button'; ed.className = 'cab-btn cab-edit'; ed.title = 'ویرایش';
    ed.innerHTML = this._ic.edit;
    ed.addEventListener('click', (e) => { e.stopPropagation(); this.openEdit(tool); });

    const dl = document.createElement('button');
    dl.type = 'button'; dl.className = 'cab-btn cab-del'; dl.title = 'حذف';
    dl.innerHTML = this._ic.del;
    dl.addEventListener('click', (e) => { e.stopPropagation(); this.askDelete(tool.id, tool.title); });

    bar.append(tgl, ed, dl);
    card.appendChild(bar);

    if (!tool.is_public) {
      const tag = document.createElement('span');
      tag.className = 'card-private-tag';
      tag.innerHTML = this._ic.lockSm + '<span>خصوصی</span>';
      card.appendChild(tag);
    }
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
    // پیش‌نمایش زنده هنگام تایپ
    ['tmTitle', 'tmDesc', 'tmBadge'].forEach(id =>
      document.getElementById(id).addEventListener('input', () => this._updatePreview()));
    // رنگ: پریست‌ها + رنگ دلخواه
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
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') { this.closeModal(); this._hideConfirm(); this._hideUnsaved(); } });
    this._modal.addEventListener('input', () => { this._dirty = true; });
    this._modal.addEventListener('change', () => { this._dirty = true; });
    this._wired = true;
  },

  // ساخت انتخابگر بصری آیکون و طرح از روی assetsCache
  _buildPickers(iconKey, decoKey) {
    this._sel.icon = iconKey || 'star';
    this._sel.deco = decoKey || 'generic';
    const icons = (typeof assetsCache !== 'undefined' && assetsCache && assetsCache.icons) ? assetsCache.icons : {};
    const decos = (typeof assetsCache !== 'undefined' && assetsCache && assetsCache.decos) ? assetsCache.decos : {};

    const ig = document.getElementById('tmIconGrid');
    ig.innerHTML = '';
    Object.keys(icons).forEach((k) => {
      const b = document.createElement('button');
      b.type = 'button';
      b.className = 'tm-icon-opt' + (k === this._sel.icon ? ' active' : '');
      b.title = k; b.dataset.key = k;
      b.innerHTML = makeSVG(k, 16);
      b.addEventListener('click', () => {
        this._sel.icon = k;
        ig.querySelectorAll('.tm-icon-opt').forEach(x => x.classList.toggle('active', x.dataset.key === k));
        this._updatePreview();
      });
      ig.appendChild(b);
    });

    const dg = document.getElementById('tmDecoGrid');
    dg.innerHTML = '';
    Object.keys(decos).forEach((k) => {
      const b = document.createElement('button');
      b.type = 'button';
      b.className = 'tm-deco-opt' + (k === this._sel.deco ? ' active' : '');
      b.dataset.key = k; b.textContent = k;
      b.addEventListener('click', () => {
        this._sel.deco = k;
        dg.querySelectorAll('.tm-deco-opt').forEach(x => x.classList.toggle('active', x.dataset.key === k));
        this._updatePreview();
      });
      dg.appendChild(b);
    });
  },

  _setColor(color) {
    this._sel.color = color || '';
    document.querySelectorAll('#tmColorPresets .tm-preset').forEach(b =>
      b.classList.toggle('active', (b.dataset.color || '') === (color || '')));
    if (color) document.getElementById('tmColor').value = color;
    this._updatePreview();
  },

  // پیش‌نمایش زنده کارت داخل مودال
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
  _unsaved: null,
  _showUnsaved() {
    if (!this._unsaved) this._unsaved = document.getElementById('toolUnsaved');
    if (!this._unsaved) return;
    this._unsaved.classList.add('open'); this._unsaved.setAttribute('aria-hidden', 'false');
    const saveBtn = document.getElementById('tmUnsavedSave');
    const cancel  = document.getElementById('tmUnsavedCancel');
    saveBtn.onclick = () => { this._hideUnsaved(); this.save(); };
    cancel.onclick  = () => { this._hideUnsaved(); this.closeModal(true); };
    this._unsaved.onclick = (e) => { if (e.target === this._unsaved) this._hideUnsaved(); };
  },
  _hideUnsaved() { if (this._unsaved) { this._unsaved.classList.remove('open'); this._unsaved.setAttribute('aria-hidden', 'true'); } },
  _show()      { this._modal.classList.add('open'); this._modal.setAttribute('aria-hidden', 'false'); },
  closeModal(force) {
    if (!force && this._dirty) { this._showUnsaved(); return; }
    this._dirty = false;
    if (this._modal) { this._modal.classList.remove('open'); this._modal.setAttribute('aria-hidden', 'true'); }
  },

  openAdd() {
    this._ensureWired(); if (!this._modal) return;
    document.getElementById('tmHeadTitle').textContent = 'افزودن ابزار';
    document.getElementById('tmId').value = '';
    ['tmTitle', 'tmDesc', 'tmPath', 'tmBadge'].forEach(id => document.getElementById(id).value = '');
    document.getElementById('tmColor').value = '#3e7de7';
    document.getElementById('tmError').textContent = '';
    this._buildPickers('star', 'generic');
    this._setColor('');
    this._show();
    this._dirty = false;
    setTimeout(() => document.getElementById('tmTitle').focus(), 50);
  },

  openEdit(tool) {
    this._ensureWired(); if (!this._modal) return;
    document.getElementById('tmHeadTitle').textContent = 'ویرایش ابزار';
    document.getElementById('tmId').value    = tool.id;
    document.getElementById('tmTitle').value = tool.title || '';
    document.getElementById('tmDesc').value  = tool.description || '';
    document.getElementById('tmPath').value  = tool.path || '';
    document.getElementById('tmBadge').value = tool.badge || '';
    document.getElementById('tmColor').value = tool.accentColor || '#3e7de7';
    document.getElementById('tmError').textContent = '';
    this._buildPickers(tool.iconKey || 'star', tool.deco || 'generic');
    this._setColor(tool.accentColor || '');
    this._show();
    this._dirty = false;
    setTimeout(() => document.getElementById('tmTitle').focus(), 50);
  },

  async save() {
    const err   = document.getElementById('tmError');
    const id    = document.getElementById('tmId').value.trim();
    const title = document.getElementById('tmTitle').value.trim();
    const path  = document.getElementById('tmPath').value.trim();
    if (!title) { err.textContent = 'عنوان الزامی است'; return; }
    if (!path)  { err.textContent = 'آدرس / مسیر الزامی است'; return; }
    const payload = {
      title,
      description: document.getElementById('tmDesc').value.trim(),
      path,
      badge:   document.getElementById('tmBadge').value.trim(),
      iconKey: this._sel.icon || 'star',
      deco:    this._sel.deco || 'generic',
      accentColor: this._sel.color || '',
    };
    if (id) payload.id = Number(id);
    const btn = document.getElementById('tmSave');
    btn.classList.add('loading'); btn.disabled = true;
    const data = await this.call(id ? 'edit' : 'add', payload);
    btn.classList.remove('loading'); btn.disabled = false;
    if (data && data.ok) { this.closeModal(true); await this.reload(); }
    else { err.textContent = (data && data.msg) || 'خطا در ذخیره'; }
  },

  async toggle(id, card, btn) {
    btn.disabled = true;
    const data = await this.call('toggle_public', { id });
    btn.disabled = false;
    if (!data || !data.ok) return;
    const nowPublic = !btn.classList.contains('is-public'); // toggle_public مقدار را در DB برعکس می‌کند
    const t = allToolsList.find(x => x.id === id); if (t) t.is_public = nowPublic;
    btn.classList.toggle('is-public', nowPublic);
    btn.innerHTML = nowPublic ? this._ic.pub : this._ic.prv;
    btn.removeAttribute('title');
    btn.setAttribute('data-tip', nowPublic ? 'عمومی — کلیک: خصوصی شود' : 'خصوصی — کلیک: عمومی شود');
    card.classList.toggle('card--private', !nowPublic);
    let tag = card.querySelector('.card-private-tag');
    if (!nowPublic && !tag) { tag = document.createElement('span'); tag.className = 'card-private-tag'; tag.innerHTML = this._ic.lockSm + '<span>خصوصی</span>'; card.appendChild(tag); }
    if (nowPublic && tag) tag.remove();
  },

  askDelete(id, title) {
    this._ensureWired(); if (!this._confirm) return;
    this._delId = id;
    document.getElementById('tmConfirmDesc').innerHTML = 'ابزار <span class="item-name">' + (title || '') + '</span> به‌طور دائم حذف خواهد شد.';
    this._confirm.classList.add('open'); this._confirm.setAttribute('aria-hidden', 'false');
  },
  _hideConfirm() { if (this._confirm) { this._confirm.classList.remove('open'); this._confirm.setAttribute('aria-hidden', 'true'); } this._delId = null; },
  async doDelete() {
    if (!this._delId) return;
    const ok = document.getElementById('tmConfirmOk'); ok.disabled = true;
    const data = await this.call('delete', { id: this._delId });
    ok.disabled = false; this._hideConfirm();
    if (data && data.ok) await this.reload();
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

  // ── مرتب‌سازی کارت‌ها (drag-drop) ─────────────────────────
  initReorder() {
    if (this._reorderWired) return;
    const toggle = document.getElementById('reorderToggle');
    if (!toggle) return;            // فقط وقتی سرور کنترل‌های ادمین را رندر کرده
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
    // همه کارت‌ها را یکجا (بدون لود تدریجی) در یک ستون رندر کن تا ترتیب کامل در DOM باشد
    grid.textContent = '';
    grid.classList.add('reordering');
    const frag = document.createDocumentFragment();
    allToolsList.forEach(t => {
      const c = createCard(t);
      c.setAttribute('draggable', 'true');
      frag.appendChild(c);
    });
    grid.appendChild(frag);
    // توقف انیمیشن‌های SMIL (<animate>/<animateMotion>/<animateTransform>) — این‌ها با
    // CSS `animation-play-state: paused` متوقف نمی‌شوند، پس مستقیما تایم‌لاین SVG را pause می‌کنیم.
    grid.querySelectorAll('svg').forEach(s => { try { s.pauseAnimations(); } catch (_) {} });
    window.scrollTo({ top: 0, behavior: 'smooth' });
  },

  exitReorder() {
    this._reordering = false;
    this._stopScroll();
    document.getElementById('reorderToggle')?.classList.remove('is-active');
    const bar = document.getElementById('reorderBar'); if (bar) bar.hidden = true;
    grid.classList.remove('reordering');
    renderTools(searchInput ? searchInput.value : '');   // بازگشت به نمای عادی
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

  // placeholder = جایگاه مقصد (هم‌اندازه کارت) که حین درگ نشان داده می‌شود
  _makePlaceholder(card) {
    const h = card.getBoundingClientRect().height;
    const ph = document.createElement('div');
    ph.className = 'card-drop-slot';
    ph.style.minHeight = Math.round(h) + 'px';
    return ph;
  },

  // placeholder را فقط وقتی جابه‌جا می‌کند که نشانگر دقیقا روی یک کارت دیگر باشد.
  // در گپ/فاصله بین کارت‌ها هیچ کاری نمی‌کند → نوسان مرزی حذف می‌شود؛ و چون پس از
  // هر درج، placeholder زیر نشانگر می‌نشیند، حرکت‌های بعدی no-op می‌شوند (پایدار).
  _movePlaceholder(x, y) {
    if (!this._ph) return;
    const under = document.elementFromPoint(x, y);
    const overCard = under && under.closest ? under.closest('.card[data-tool-id]') : null;
    if (!overCard || overCard === this._dragCard) return;   // گپ یا مبدأ → جابه‌جا نکن
    const r = overCard.getBoundingClientRect();
    const cx = r.left + r.width / 2, cy = r.top + r.height / 2;
    const sameRow = Math.abs(y - cy) < r.height / 2;
    const before = sameRow ? (x > cx) : (y < cy);   // RTL: سمت راست = جلوتر
    const ref = before ? overCard : overCard.nextSibling;
    if (ref === this._ph) return;
    if (this._ph.nextSibling === ref) return;       // از قبل همین‌جاست → جابه‌جا نکن
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

  // اسکرول خودکار لبه: وقتی نشانگر/انگشت نزدیک بالا یا پایین صفحه می‌رود،
  // صفحه خودکار اسکرول می‌شود تا بشود کارت را به ردیف‌های خارج از دید برد.
  // (حین درگ، اسکرول معمولی کار نمی‌کند؛ این جایگزین آن است — دسکتاپ + لمسی.)
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
    this._movePlaceholder(this._lastX, this._lastY);   // محتوا زیر نشانگر تغییر کرد → به‌روزرسانی
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

    // ── دسکتاپ: HTML5 Drag & Drop (مبدأ مخفی + placeholder مقصد) ──
    grid.addEventListener('dragstart', (e) => {
      if (!active()) { e.preventDefault(); return; }
      const card = e.target.closest('.card');
      if (!card) return;
      this._dragCard = card;
      if (e.dataTransfer) { e.dataTransfer.effectAllowed = 'move'; try { e.dataTransfer.setData('text/plain', card.dataset.toolId || ''); } catch (_) {} }
      // اسنپ‌شات تصویر درگ همین حالا گرفته می‌شود؛ در تیک بعد مبدأ مخفی و placeholder درج می‌شود
      setTimeout(() => {
        if (!this._dragCard) return;
        this._ph = this._makePlaceholder(card);
        grid.insertBefore(this._ph, card);
        card.classList.add('card--dragging');
        document.body.classList.add('is-dragging');   // توقف همه انیمیشن‌های متحرک حین درگ
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
    // در حالت مرتب‌سازی کلیک ناوبری کارت غیرفعال است
    grid.addEventListener('click', (e) => { if (active()) { e.preventDefault(); e.stopPropagation(); } }, true);

    // ── لمسی (موبایل): کلون شناور مبدأ + همان placeholder مقصد ──
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
      this._clone.querySelectorAll('svg').forEach(s => { try { s.pauseAnimations(); } catch (_) {} }); // SMIL کلون هم متوقف
      this._ph = this._makePlaceholder(card);
      grid.insertBefore(this._ph, card);
      card.classList.add('card--dragging');
      document.body.classList.add('is-dragging');   // توقف همه انیمیشن‌های متحرک حین درگ
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
  // کنترل‌های مرتب‌سازی ادمین (فقط وقتی سرور آن‌ها را رندر کرده باشد)
  AdminTools.initReorder();
}

// اگر این صفحه prerender شده باشد، init را تا «فعال‌سازی» (نمایش واقعی) به تعویق می‌اندازیم؛
// در غیر این صورت داده‌ها با نشست مهمان زمان prerender گرفته می‌شد و پس از ورود
// تا رفرش، حالت مهمان نمایش داده می‌ماند. حالا با نشست فعلی (بعد از ورود) لود می‌شود.
if (document.prerendering) {
  document.addEventListener('prerenderingchange', boot, { once: true });
} else {
  boot();
}