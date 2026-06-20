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
    // توقفِ انیمیشن‌های پس‌زمینه تا backdrop-blur هر فریم بازمحاسبه نشود
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
  _loaded:        false,   // آیا لیستِ کامل (لودِ تنبل) آمده است؟
  _loading:       false,   // گاردِ ضدِّ فراخوانیِ هم‌زمان

  async load() {
    if (this._loading) return;          // جلوگیری از فراخوانیِ هم‌زمانِ دوگانه
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
      // اگر پنل هنگامِ لودِ پس‌زمینه باز بود، حالا با دادهٔ واقعی رندر کن
      if (this._open) this._renderDropdown();
    } catch {
      // در خطا، state قبلی را پاک نکن (ممکن است از لودِ قبلی معتبر باشد)
      this._updateBadge();
    } finally {
      this._loading = false;
    }
  },

  reset() {
    this._notifications = [];
    this._unreadCount   = 0;
    this._page          = 1;
    this._loaded        = false;   // تا در ورود/خروجِ بعدی دوباره لود شود
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
    // اگر لیستِ تنبل هنوز نیامده، همین حالا بیاور (load خودش بعدِ آمدن رندر می‌کند)
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
            ${ago}${hasImg ? ' &nbsp;·&nbsp; <span style="opacity:.7;">📎</span>' : ''}
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
      دستهٔ بعدی فقط وقتی کاربر به انتهای لیست نزدیک شد رندر می‌شود.
      این کار از ساختِ یکجای صدها کارت + انیمیشن deco جلوگیری می‌کند. */
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

  // observe فقط کارت‌های تازه برای pause انیمیشنِ off-screen
  const obs = getCardVisibilityObserver();
  if (obs) newCards.forEach(c => obs.observe(c));

  // اگر هنوز کارتی مانده، sentinel بساز و رصد کن تا دستهٔ بعد لود شود
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
searchInput.addEventListener('keydown', e => {
  if (e.key === 'Escape' && searchInput.value) clearSearch();
});
searchInput.addEventListener('paste', () => {
  setTimeout(() => handleSearch(searchInput.value), 0);
});
clearButton.addEventListener('click', clearSearch);

mainContent.addEventListener('keydown', e => {
  if (e.key === '/' && document.activeElement !== searchInput && !e.ctrlKey && !e.metaKey) {
    e.preventDefault();
    searchInput.focus();
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

  // اعلان‌ها دیگر در bootstrap حمل نمی‌شوند (تا کارت‌ها منتظرِ ~۱۰۵KB نمانند).
  // فقط شمارشِ اولیه (کاربرِ لاگین‌شده) ست می‌شود تا بَج فوری ظاهر شود؛
  // لیستِ کامل در startRealtime() به‌صورت پس‌زمینه لود می‌شود.
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
  // لودِ غیرمسدودکنندهٔ لیستِ اعلان‌ها بعد از رندرِ کارت‌ها (دیگر بخشی از bootstrap نیست)
  NotifPanel.load();
  NotifPanel.startPolling();
  document.addEventListener('visibilitychange', () => {
    if (!document.hidden) NotifPanel._poll();
  });
  window.addEventListener('focus', () => NotifPanel._poll());
}

function boot() {
  document.readyState === 'loading'
    ? document.addEventListener('DOMContentLoaded', init)
    : init();
}

// اگر این صفحه prerender شده باشد، init را تا «فعال‌سازی» (نمایش واقعی) به تعویق می‌اندازیم؛
// در غیر این صورت داده‌ها با نشست مهمان زمان prerender گرفته می‌شد و پس از ورود
// تا رفرش، حالت مهمان نمایش داده می‌ماند. حالا با نشست فعلی (بعد از ورود) لود می‌شود.
if (document.prerendering) {
  document.addEventListener('prerenderingchange', boot, { once: true });
} else {
  boot();
}