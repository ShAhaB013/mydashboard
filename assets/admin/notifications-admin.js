'use strict';

const Toast = {
  _t: null,
  show(msg, type = 'success') {
    const el = document.getElementById('toast');
    document.getElementById('toastMsg').textContent = msg;
    document.getElementById('toastIcon').innerHTML  = type === 'success'
      ? '<polyline points="20 6 9 17 4 12"/>'
      : '<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>';
    el.className = `toast ${type} show`;
    clearTimeout(this._t);
    this._t = setTimeout(() => el.classList.remove('show'), 2800);
  },
};

async function apiCall(action, body = {}) {
  try {
    const res = await fetch(`admin.php?api=${action}`, {
      method:  'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
      body:    JSON.stringify(body),
    });
    return await res.json();
  } catch {
    return { ok: false, msg: 'خطا در ارتباط با سرور' };
  }
}

// ═══════════════════════════════════════════════════════════
// RTE — ویرایشگر متن غنی برای متن اعلان
// ═══════════════════════════════════════════════════════════
const RTE = {
  MAX_CHARS: 20000,
  _el: null,
  _savedRange: null,
  _lastColor: '#e11d48',
  _colorMarker: null,

  init() {
    this._el = document.getElementById('nf-body');
    if (!this._el) return;

    // دکمه‌های فرمان (execCommand)
    document.querySelectorAll('#rteToolbar .rte-btn[data-cmd]').forEach(btn => {
      btn.addEventListener('mousedown', e => e.preventDefault()); // حفظ انتخاب
      btn.addEventListener('click', () => {
        this._el.focus();
        // فرمان‌های ساختاری (bold/italic/underline/lists/...) تگ‌های تمیز تولید کنند
        try { document.execCommand('styleWithCSS', false, false); } catch {}
        document.execCommand(btn.dataset.cmd, false, null);
        this._sync();
      });
    });

    // دکمه‌های جهت (RTL/LTR)
    document.querySelectorAll('#rteToolbar .rte-btn[data-dir]').forEach(btn => {
      btn.addEventListener('mousedown', e => e.preventDefault());
      btn.addEventListener('click', () => {
        this._el.focus();
        this._applyDir(btn.dataset.dir);
        this._sync();
      });
    });

    // ── انتخاب‌گر رنگ بومی ──
    this._initColorInput();

    // ذخیره انتخاب در هر تغییر داخل ویرایشگر
    this._el.addEventListener('keyup',   () => this._saveSelection());
    this._el.addEventListener('mouseup', () => this._saveSelection());
    // مطمئن‌ترین راه: هر تغییر انتخاب در صفحه که داخل ویرایشگر باشد ذخیره شود
    document.addEventListener('selectionchange', () => {
      const sel = window.getSelection();
      if (sel && sel.rangeCount && this._el &&
          this._el.contains(sel.getRangeAt(0).commonAncestorContainer) &&
          !sel.getRangeAt(0).collapsed) {
        this._savedRange = sel.getRangeAt(0).cloneRange();
      }
    });

    // رویدادهای ورودی
    this._el.addEventListener('input',  () => this._sync());
    this._el.addEventListener('keyup',  () => this._updateActive());
    this._el.addEventListener('mouseup',() => this._updateActive());

    // میانبرها
    this._el.addEventListener('keydown', e => {
      if (e.ctrlKey || e.metaKey) {
        const k = e.key.toLowerCase();
        if (k === 'b' || k === 'i' || k === 'u') setTimeout(() => this._sync(), 0);
      }
    });

    // شروع هر انتخاب جدید در ویرایشگر → marker رنگ نهایی‌نشده‌ی قبلی را پاک‌سازی کن
    // (وگرنه اگر دیالوگ رنگ بدون تایید بسته شده باشد، رنگ بعدی روی متن قبلی می‌نشیند)
    this._el.addEventListener('mousedown', () => { if (this._colorMarker) this._finalizeColorTarget(); });
    this._el.addEventListener('keydown',   () => { if (this._colorMarker) this._finalizeColorTarget(); });

    // چسباندن (paste) به‌صورت متن ساده تا کدهای ناخواسته وارد نشوند
    this._el.addEventListener('paste', e => {
      e.preventDefault();
      const text = (e.clipboardData || window.clipboardData).getData('text/plain');
      document.execCommand('insertText', false, text);
    });

    this._sync();
  },

  // ── انتخاب‌گر رنگ بومی ساده (با حفظ درست انتخاب متن) ──
  _initColorInput() {
    const input  = document.getElementById('rteColor');
    const swatch = document.getElementById('rteColorSwatch');
    if (!input) return;

    this._lastColor = this._lastColor || '#e11d48';
    input.value = this._lastColor;
    if (swatch) swatch.style.background = this._lastColor;

    // درست قبل از باز شدن دیالوگ رنگ: انتخاب متن را در یک span موقت بپیچ
    // هر دو رویداد پوشش داده می‌شود؛ محافظ ضدتکرار در _markColorTarget است
    const startMark = () => { this._saveSelection(); this._markColorTarget(); };
    input.addEventListener('pointerdown', startMark);
    input.addEventListener('mousedown',  startMark);
    input.addEventListener('click',      startMark);

    // حین کشیدن در دیالوگ، رنگ روی همان محدوده علامت‌خورده اعمال می‌شود
    const apply = () => {
      if (swatch) swatch.style.background = input.value;
      this._lastColor = input.value;
      this._colorMarkedTarget(input.value);
      this._sync();
    };
    input.addEventListener('input',  apply);
    input.addEventListener('change', () => {
      apply();
      // span رنگی را تثبیت کن (با کمی تاخیر تا blur تداخل نکند)
      setTimeout(() => this._finalizeColorTarget(), 0);
    });
  },

  /* محدوده انتخاب‌شده را با یک span نشانه‌گذاری می‌کند تا با تغییر رنگ از بین نرود */
  _markColorTarget() {
    // اگر marker از قبل وجود دارد (مثلا رویداد دوبار صدا شد) دوباره نساز
    if (this._colorMarker && this._colorMarker.isConnected) return;
    this._colorMarker = null;

    let range = this._savedRange;
    if (!range) {
      const sel = window.getSelection();
      if (sel && sel.rangeCount) range = sel.getRangeAt(0);
    }
    if (!range || range.collapsed) return;
    if (!this._el.contains(range.commonAncestorContainer)) return;

    const span = document.createElement('span');
    span.setAttribute('data-color-marker', '1');
    try {
      range.surroundContents(span);
      this._colorMarker = span;
    } catch {
      try {
        const frag = range.extractContents();
        span.appendChild(frag);
        range.insertNode(span);
        this._colorMarker = span;
      } catch { this._colorMarker = null; }
    }
  },

  /* رنگ را روی محتوای span نشانه‌گذاری‌شده اعمال می‌کند (بدون نیاز به انتخاب) */
  _colorMarkedTarget(hex) {
    if (this._colorMarker && this._colorMarker.isConnected) {
      this._colorMarker.style.color = hex;
    }
  },

  /* span رنگی را نهایی می‌کند (نشانه موقت را برمی‌دارد، رنگ می‌ماند) */
  _finalizeColorTarget() {
    const span = this._colorMarker;
    this._colorMarker = null;
    if (!span || !span.isConnected) return;
    if (span.style.color) {
      span.removeAttribute('data-color-marker');
    } else {
      // رنگی اعمال نشده → span را باز کن
      const parent = span.parentNode;
      while (span.firstChild) parent.insertBefore(span.firstChild, span);
      parent.removeChild(span);
      if (parent.normalize) parent.normalize();
    }
  },

  _applyDir(dir) {
    // جهت را روی بلوک فعلی اعمال کن
    let node = window.getSelection().anchorNode;
    if (!node) return;
    if (node.nodeType === 3) node = node.parentNode;
    // نزدیک‌ترین بلوک داخل ویرایشگر
    let block = node;
    while (block && block !== this._el && !/^(P|DIV|LI|UL|OL|H[1-6])$/.test(block.tagName)) {
      block = block.parentNode;
    }
    if (!block || block === this._el) {
      // اگر بلوک مشخصی نبود، کل ویرایشگر را تنظیم کن
      this._el.setAttribute('dir', dir);
      this._el.style.textAlign = dir === 'rtl' ? 'right' : 'left';
    } else {
      block.setAttribute('dir', dir);
    }
  },

  _sync() {
    this._updateCounter();
    this._updateActive();
  },

  // ── ذخیره/بازیابی انتخاب متن (برای color picker که فوکوس را می‌برد) ──
  _saveSelection() {
    const sel = window.getSelection();
    if (!sel || sel.rangeCount === 0) return;
    const range = sel.getRangeAt(0);
    // فقط اگر انتخاب داخل ویرایشگر است ذخیره کن
    if (this._el && this._el.contains(range.commonAncestorContainer)) {
      this._savedRange = range.cloneRange();
    }
  },

  _restoreSelection() {
    if (!this._savedRange) return;
    const sel = window.getSelection();
    sel.removeAllRanges();
    sel.addRange(this._savedRange);
  },

  _updateActive() {
    document.querySelectorAll('#rteToolbar .rte-btn[data-cmd]').forEach(btn => {
      const cmd = btn.dataset.cmd;
      let on = false;
      try { on = document.queryCommandState(cmd); } catch {}
      btn.classList.toggle('active', !!on);
    });
  },

  _updateCounter() {
    const len  = this.plainLength();
    const cEl  = document.getElementById('rteCount');
    const wrap = document.getElementById('rteCounter');
    if (cEl)  cEl.textContent = len.toLocaleString('en-US');
    if (wrap) wrap.classList.toggle('over', len > this.MAX_CHARS);
  },

  plainLength() {
    if (!this._el) return 0;
    // طول متن قابل‌مشاهده (بدون تگ‌ها)
    return (this._el.innerText || this._el.textContent || '').trim().length;
  },

  setHTML(html) {
    if (!this._el) this._el = document.getElementById('nf-body');
    this._el.innerHTML = RTE.sanitize(html || '');
    this._sync();
  },

  getHTML() {
    if (!this._el) return '';
    // اگر فقط فضای خالی است، رشته خالی برگردان
    if (this.plainLength() === 0 && !/<(img|br|hr)/i.test(this._el.innerHTML)) return '';
    return RTE.sanitize(this._el.innerHTML);
  },

  // پاک‌سازی HTML (هماهنگ با سمت سرور)
  sanitize(html) {
    const ALLOWED_TAGS  = ['B','STRONG','I','EM','U','BR','P','DIV','SPAN','UL','OL','LI','A','FONT'];
    const ALLOWED_ATTRS = ['style','dir','href','target','rel','color','align'];
    const ALLOWED_CSS   = ['text-align','color','background-color','font-weight','font-style','text-decoration','direction'];
    const tpl = document.createElement('template');
    tpl.innerHTML = String(html ?? '');

    const walk = node => {
      [...node.childNodes].forEach(child => {
        if (child.nodeType === 1) {
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
          walk(child);
        } else if (child.nodeType !== 3) {
          child.remove();
        }
      });
    };
    walk(tpl.content);
    return tpl.innerHTML;
  },
};

const NM = {
  _notifications: [],
  _dirty:         false,
  _editId:        null,
  _pendingImage:  null,
  _pendingThumb:  null,
  _existingImage: null,
  _existingThumb: null,
  _xhr:           null,
  _previewURL:    null,
  _deleteId:      null,
  _page:          1,
  _perPage:       10,
  _search:        '',
  _fDateFrom:     '',
  _fDateTo:       '',
  _fStatus:       '',
  _total:         0,
  _pageCount:     1,
  _loading:       false,
  _searchTimer:   null,

  async load(page = this._page) {
    if (this._loading) return;
    this._loading = true;
    this._page    = Math.max(1, page);

    const res = await apiCall('list_notifications', {
      page:      this._page,
      per_page:  this._perPage,
      search:    this._search,
      date_from: this._fDateFrom,
      date_to:   this._fDateTo,
      status:    this._fStatus,
    });

    this._loading = false;
    if (!res.ok) { Toast.show(res.msg || 'خطا در بارگذاری', 'error'); return; }

    this._notifications = res.notifications || [];
    const pg = res.pagination || {};
    this._total     = pg.total      ?? this._notifications.length;
    this._pageCount  = pg.page_count ?? 1;
    this._page       = pg.page       ?? this._page;

    // اگر صفحه فعلی خالی شد (مثلا بعد از حذف آخرین آیتم صفحه)، یک صفحه عقب برو
    if (!this._notifications.length && this._page > 1) {
      return this.load(this._page - 1);
    }

    this._render();
  },

  _render() {
    const list  = document.getElementById('notifList');
    const badge = document.getElementById('notifCountBadge');
    badge.textContent = this._total;

    if (!this._notifications.length) {
      const emptyMsg = this._search
        ? 'نتیجه‌ای برای جستجوی شما یافت نشد'
        : 'هیچ اعلانی ثبت نشده است';
      list.innerHTML = `
        <div class="notif-empty">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
            <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
            <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
          </svg>
          <p>${this._esc(emptyMsg)}</p>
        </div>`;
      this._renderPagination();
      return;
    }

    list.innerHTML = '';
    const frag = document.createDocumentFragment();
    this._notifications.forEach((n, i) => frag.appendChild(this._makeRow(n, i)));
    list.appendChild(frag);

    this._renderPagination();
  },

  // ── Pagination (سمت سرور) ─────────────────────────────
  _renderPagination() {
    const pag  = document.getElementById('notifPagination');
    const info = document.getElementById('notifPageInfo');
    const total     = this._total;
    const pageCount = this._pageCount;
    const cur       = this._page;
    const shown     = this._notifications.length;

    if (total === 0) {
      pag.classList.add('hidden');
      pag.innerHTML = '';
      info.textContent = '';
      return;
    }

    const start = (cur - 1) * this._perPage;
    info.textContent = `نمایش ${start + 1} تا ${start + shown} از ${total} اعلان`;

    if (pageCount <= 1) {
      pag.classList.add('hidden');
      pag.innerHTML = '';
      return;
    }

    const items = [];

    // دکمه قبلی
    items.push(`<button class="page-btn" ${cur === 1 ? 'disabled' : ''} onclick="NM.goToPage(${cur - 1})" title="قبلی">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M9 18l6-6-6-6"/></svg>
    </button>`);

    // بازه صفحات با ... برای تعداد زیاد
    this._pageRange(cur, pageCount).forEach(p => {
      if (p === '...') {
        items.push(`<span class="page-ellipsis">…</span>`);
      } else {
        items.push(`<button class="page-btn ${p === cur ? 'active' : ''}" onclick="NM.goToPage(${p})">${p}</button>`);
      }
    });

    // دکمه بعدی
    items.push(`<button class="page-btn" ${cur === pageCount ? 'disabled' : ''} onclick="NM.goToPage(${cur + 1})" title="بعدی">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M15 18l-6-6 6-6"/></svg>
    </button>`);

    pag.innerHTML = items.join('');
    pag.classList.remove('hidden');
  },

  /** ساخت بازه شماره صفحات با ... وقتی تعداد صفحات زیاد است */
  _pageRange(cur, count) {
    if (count <= 7) {
      return Array.from({ length: count }, (_, i) => i + 1);
    }
    const range = [1];
    const left  = Math.max(2, cur - 1);
    const right = Math.min(count - 1, cur + 1);
    if (left > 2) range.push('...');
    for (let i = left; i <= right; i++) range.push(i);
    if (right < count - 1) range.push('...');
    range.push(count);
    return range;
  },

  goToPage(p) {
    p = Math.min(Math.max(1, p), this._pageCount);
    if (p === this._page) return;
    this.load(p).then(() => {
      window.scrollTo({ top: 0, behavior: 'smooth' });
    });
  },

  // ── جستجو (با debounce) ───────────────────────────────
  onSearchInput(value) {
    const wrap = document.querySelector('.notif-search');
    if (wrap) wrap.classList.toggle('has-value', value.trim() !== '');
    clearTimeout(this._searchTimer);
    this._searchTimer = setTimeout(() => {
      const v = value.trim();
      if (v === this._search) return;
      this._search = v;
      this.load(1);
    }, 350);
  },

  clearSearch() {
    const inp  = document.getElementById('notifSearchInput');
    const wrap = document.querySelector('.notif-search');
    if (inp)  inp.value = '';
    if (wrap) wrap.classList.remove('has-value');
    if (this._search === '') return;
    this._search = '';
    this.load(1);
  },

  // ── جستجوی پیشرفته ─────────────────────────────────────
  toggleAdvanced() {
    const panel = document.getElementById('nmAdvPanel');
    const btn   = document.getElementById('nmAdvToggle');
    if (!panel) return;
    const open = panel.classList.toggle('open');
    if (btn) { btn.classList.toggle('active', open); btn.setAttribute('aria-expanded', open ? 'true' : 'false'); }
  },

  applyFilters() {
    this._fDateFrom = document.getElementById('nm-df').value || '';
    this._fDateTo   = document.getElementById('nm-dt').value || '';
    this._fStatus   = document.getElementById('nm-st').value || '';
    this._syncAdvBtn();
    this.load(1);
  },

  resetFilters() {
    document.getElementById('nm-df').value = '';
    document.getElementById('nm-dt').value = '';
    document.getElementById('nm-st').value = '';
    if (window.CSelect) CSelect.refresh(document.getElementById('nm-st'));
    if (window.ThemedDatePicker) {
      ThemedDatePicker.refresh(document.getElementById('nm-df'));
      ThemedDatePicker.refresh(document.getElementById('nm-dt'));
    }
    this._fDateFrom = this._fDateTo = this._fStatus = '';
    this._syncAdvBtn();
    this.load(1);
  },

  _syncAdvBtn() {
    const has = !!(this._fDateFrom || this._fDateTo || this._fStatus);
    const btn = document.getElementById('nmAdvToggle');
    if (btn) btn.classList.toggle('has-filters', has);
  },

  _makeRow(n, idx = 0) {
    const row = document.createElement('div');
    row.className = `notif-row${n.is_expired ? ' is-expired' : ''}`;
    row.dataset.id = n.id;

    // شماره ردیف با احتساب صفحه‌بندی
    const rowNum = (this._page - 1) * this._perPage + idx + 1;

    const pills = [];
    if (n.is_expired) pills.push(`<span class="pill pill-expired">منقضی‌شده</span>`);
    else              pills.push(`<span class="pill pill-active">فعال</span>`);
    if (n.is_public)        pills.push(`<span class="pill pill-public">عمومی</span>`);
    if (n.target_all_users) pills.push(`<span class="pill pill-all">همه کاربران</span>`);
    (n.badges || []).forEach(b => pills.push(`<span class="pill pill-badge">${this._esc(b)}</span>`));
    // تاریخ و ساعت انتشار و انقضا — برچسب‌دار و کنار هم
    const _fmtDT = ms => new Date(ms).toLocaleString('fa-IR', { dateStyle: 'short', timeStyle: 'short' });
    pills.push(`<span class="pill pill-created" title="تاریخ و ساعت انتشار">انتشار: ${_fmtDT(n.created_at)}</span>`);
    if (n.expires_at) {
      pills.push(`<span class="pill pill-expiry" title="تاریخ و ساعت انقضا">انقضا: ${_fmtDT(n.expires_at * 1000)}</span>`);
    } else {
      pills.push(`<span class="pill pill-noexp" title="بدون تاریخ انقضا">بدون انقضا</span>`);
    }

    row.innerHTML = `
      <div class="notif-row-num" aria-hidden="true">${rowNum.toLocaleString('fa-IR')}</div>
      <div class="notif-row-body">
        <div class="notif-row-title">${this._esc(n.title)}</div>
        ${n.body ? `<div class="notif-row-text">${this._esc(this._stripTags(n.body))}</div>` : ''}
        <div class="notif-row-meta">
          ${pills.join('')}
        </div>
      </div>
      <div class="notif-row-actions">
        <button class="btn btn-secondary btn-icon btn-sm" title="ویرایش" onclick="NM.openEdit(${n.id})">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/>
            <path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/>
          </svg>
        </button>
        <button class="btn btn-danger btn-icon btn-sm" title="حذف" onclick="NM.openDelete(${n.id}, '${this._escAttr(n.title)}')">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <polyline points="3 6 5 6 21 6"/>
            <path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/>
            <path d="M10 11v6M14 11v6M9 6V4a1 1 0 011-1h4a1 1 0 011 1v2"/>
          </svg>
        </button>
      </div>`;
    return row;
  },

  // ── helpers برای فرمت تاریخ ───────────────────────────
  /**
   * تبدیل Unix timestamp به تاریخ و ساعت جداگانه (به وقت محلی مرورگر)
   */
  _tsToDateAndTime(ts) {
    if (!ts) return { date: '', time: '00:00' };
    const d   = new Date(ts * 1000);
    const pad = v => String(v).padStart(2, '0');
    return {
      date: `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}`,
      time: `${pad(d.getHours())}:${pad(d.getMinutes())}`,
    };
  },

  /** نمایش تاریخ خوانا زیر input */
  _showExpiryDisplay(ts) {
    const wrap = document.getElementById('expiryDisplay');
    const txt  = document.getElementById('expiryDisplayText');
    if (ts) {
      const d = new Date(ts * 1000);
      const date = d.toLocaleDateString('fa-IR', { year:'numeric', month:'long', day:'numeric' });
      const time = d.toLocaleTimeString('fa-IR', { hour:'2-digit', minute:'2-digit' });
      txt.textContent = `${date} — ساعت ${time}`;
      wrap.classList.add('show');
    } else {
      wrap.classList.remove('show');
      txt.textContent = '';
    }
  },

  /** وقتی کاربر تاریخ یا ساعت انقضا را تغییر می‌دهد */
  onExpiryInput() {
    const date = document.getElementById('nf-expires-date').value;
    const time = document.getElementById('nf-expires-time').value || '00:00';
    if (date) {
      // تبدیل به UTC timestamp برای نمایش صحیح
      const localDt = new Date(`${date}T${time}:00`);
      if (!isNaN(localDt.getTime())) {
        this._showExpiryDisplay(Math.floor(localDt.getTime() / 1000));
      }
    } else {
      this._showExpiryDisplay(0);
    }
  },

  // ── Form ──────────────────────────────────────────────
  _resetForm() {
    document.getElementById('nf-title').value        = '';
    RTE.setHTML('');
    document.getElementById('nf-expires-date').value = '';
    if (window.ThemedDatePicker) ThemedDatePicker.refresh(document.getElementById('nf-expires-date'));
    document.getElementById('nf-expires-time').value = '00:00';
    if (window.ThemedTimePicker) ThemedTimePicker.refresh(document.getElementById('nf-expires-time'));
    document.getElementById('nf-public').checked     = false;
    document.getElementById('nf-all-users').checked  = false;
    document.querySelectorAll('.badge-check-cb').forEach(cb => cb.checked = false);
    this._showExpiryDisplay(0);
    if (this._xhr) { try { this._xhr.abort(); } catch (e) {} this._xhr = null; }
    this._pendingImage  = null;
    this._pendingThumb  = null;
    this._existingImage = null;
    this._existingThumb = null;
    this._resetFileUI();
    this.onPublicChange(document.getElementById('nf-public'));
  },

  openAdd() {
    this._editId = null;
    document.getElementById('notifModalTitle').textContent = 'اعلان جدید';
    this._buildBadgeGrid([]);
    this._resetForm();
    this._openModal('notifFormModal');
    this._dirty = false;
    setTimeout(() => document.getElementById('nf-title').focus(), 100);
  },

  openEdit(id) {
    const n = this._notifications.find(x => x.id === id);
    if (!n) return;
    this._editId = id;
    document.getElementById('notifModalTitle').textContent = 'ویرایش اعلان';
    this._buildBadgeGrid(n.badges || []);
    this._resetForm();

    document.getElementById('nf-title').value       = n.title   || '';
    RTE.setHTML(n.body || '');
    document.getElementById('nf-public').checked    = !!n.is_public;
    document.getElementById('nf-all-users').checked = !!n.target_all_users;

    (n.badges || []).forEach(b => {
      const cb = document.querySelector(`.badge-check-cb[value="${CSS.escape(b)}"]`);
      if (cb) cb.checked = true;
    });

    // ── تنظیم تاریخ و ساعت انقضا ────────────────────────
    if (n.expires_at) {
      const { date, time } = this._tsToDateAndTime(n.expires_at);
      document.getElementById('nf-expires-date').value = date;
      if (window.ThemedDatePicker) ThemedDatePicker.refresh(document.getElementById('nf-expires-date'));
      document.getElementById('nf-expires-time').value = time;
      if (window.ThemedTimePicker) ThemedTimePicker.refresh(document.getElementById('nf-expires-time'));
      this._showExpiryDisplay(n.expires_at);
    }

    if (n.image_path) {
      this._existingImage = n.image_path;
      this._existingThumb = n.thumbnail_path || null;
      this._showExistingImage(n.image_path, n.thumbnail_path);
    }

    this.onPublicChange(document.getElementById('nf-public'));
    this._openModal('notifFormModal');
    this._dirty = false;
    setTimeout(() => document.getElementById('nf-title').focus(), 100);
  },

  // ── تعداد آیتم در هر صفحه (قابل تنظیم + ماندگار) ─────────
  setPerPage(val) {
    const allowed = [10, 20, 50];
    let n = parseInt(val, 10);
    if (!allowed.includes(n)) n = 10;
    this._perPage = n;
    try { localStorage.setItem('notif_admin_perpage', String(n)); } catch (e) {}
    this.load(1);
  },
  _initPerPage() {
    let n = 10;
    try {
      const saved = parseInt(localStorage.getItem('notif_admin_perpage'), 10);
      if ([10, 20, 50].includes(saved)) n = saved;
    } catch (e) {}
    this._perPage = n;
    const sel = document.getElementById('notifPerPage');
    if (sel) sel.value = String(n);
  },

  // ── ردیابی تغییرات ذخیره‌نشده (مثل فرم کارت‌ها) ──────────
  _markDirty() { this._dirty = true; },
  _initDirty() {
    const modal = document.getElementById('notifFormModal');
    if (!modal) return;
    // یک شنونده‌ی واحد: هر تغییر کاربر داخل فرم (متن، تاریخ، چک‌باکس،
    // محتوای ویرایشگر، آپلود) فرم را «تغییر‌یافته» علامت می‌زند.
    const mark = () => this._markDirty();
    modal.addEventListener('input',  mark);
    modal.addEventListener('change', mark);
  },

  closeForm(force = false) {
    // اگر تغییر ذخیره‌نشده وجود دارد، با مودال سفارشی تایید بگیر
    if (!force && this._dirty) {
      this._ask({
        title:    'بستن فرم',
        heading:  'تغییرات ذخیره‌نشده دارید',
        desc:     'تغییرات را ذخیره نکرده‌اید، آیا از بستن فرم اطمینان دارید؟',
        type:     'warning',
        icon:     '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
        btnLabel: 'بستن بدون ذخیره',
        onConfirm: () => { this.closeConfirm(); this.closeForm(true); },
      });
      return;
    }
    this._dirty = false;
    this._closeModal('notifFormModal');
    this._pendingImage  = null;
    this._existingImage = null;
  },

  _buildBadgeGrid(selected) {
    const grid = document.getElementById('badgeCheckGrid');
    if (!AVAIL_BADGES.length) {
      grid.innerHTML = '<span class="badge-check-empty">هیچ دسته‌بندی‌ای موجود نیست</span>';
      return;
    }
    grid.innerHTML = '';
    AVAIL_BADGES.forEach(badge => {
      const label = document.createElement('label');
      label.className = 'badge-check-label';
      const cb = document.createElement('input');
      cb.type = 'checkbox'; cb.className = 'badge-check-cb'; cb.value = badge;
      cb.checked = selected.includes(badge);
      label.appendChild(cb);
      label.appendChild(document.createTextNode(badge));
      grid.appendChild(label);
    });
  },

  onPublicChange(cb) {
    const hide = cb.checked;
    document.getElementById('targetAllRow').style.opacity = hide ? '.4' : '';
    document.getElementById('badgesRow').style.opacity    = hide ? '.4' : '';
    document.getElementById('nf-all-users').disabled      = hide;
    document.querySelectorAll('.badge-check-cb').forEach(c => c.disabled = hide);
  },

  // ── آپلود تصویر (تک‌فایل، با نوار پیشرفت زنده) ────────
  handleFileSelect(file) {
    if (!file) return;
    if (!file.type.startsWith('image/')) { Toast.show('فقط فایل‌های تصویری مجاز هستند', 'error'); return; }
    if (file.size > 52_428_800)          { Toast.show('حجم فایل بیشتر از ۵۰ مگابایت است', 'error'); return; }

    // نمایش فوری ردیف فایل + ساخت پیش‌نمایش کوچک خارج از thread اصلی
    // (تصویر تمام‌اندازه را مستقیم به <img> نمی‌دهیم تا UI با عکس‌های سنگین فریز نشود)
    this._showFileItem({ name: file.name });
    this._setPreviewFromFile(file);
    this._setFileState('uploading');
    this._setFileProgress(0, 0, file.size);

    const formData = new FormData();
    formData.append('image', file);

    const xhr = new XMLHttpRequest();
    this._xhr = xhr;
    xhr.open('POST', 'admin.php?api=upload_notification_image');
    xhr.setRequestHeader('X-CSRF-Token', CSRF_TOKEN);
    xhr.timeout = 300_000; // ۵ دقیقه برای تصاویر بزرگ

    xhr.upload.onprogress = e => {
      if (e.lengthComputable) {
        this._setFileProgress(Math.round((e.loaded / e.total) * 100), e.loaded, e.total);
      }
    };
    xhr.onload = () => {
      this._xhr = null;
      let data = {};
      try { data = JSON.parse(xhr.responseText); } catch (e) {}
      if (xhr.status >= 200 && xhr.status < 300 && data.ok) {
        this._pendingImage = data.image_path;
        this._pendingThumb = data.thumbnail_path || null;
        this._setFileProgress(100, file.size, file.size);
        this._setFileState('done');
        // پیش‌نمایش را به نسخه نهایی سرور سوییچ کن و objectURL را آزاد کن
        document.getElementById('imgPreview').src = data.thumbnail_path || data.image_path;
        this._revokePreview();
        this._markDirty();
        Toast.show('تصویر با موفقیت آپلود شد');
      } else {
        this._revokePreview();
        this._fileError(data.msg || 'خطا در آپلود');
      }
    };
    xhr.onerror   = () => { this._xhr = null; this._revokePreview(); this._fileError('خطا در ارتباط با سرور'); };
    xhr.ontimeout = () => { this._xhr = null; this._revokePreview(); this._fileError('آپلود به دلیل طولانی شدن لغو شد'); };
    xhr.send(formData);
  },

  _fmtBytes(n) {
    n = Number(n) || 0;
    if (n < 1024)    return n + ' B';
    if (n < 1048576) return Math.round(n / 1024) + ' KB';
    return (n / 1048576).toFixed(1) + ' MB';
  },
  _basename(p) { return String(p || '').split('/').pop().split('\\').pop() || 'تصویر'; },

  // ساخت پیش‌نمایش کوچک بدون فریز: createImageBitmap تصویر را خارج از thread
  // اصلی decode و resize می‌کند، سپس یک بندانگشتی سبک (≈۲۰۰px) روی <img> می‌نشیند.
  async _setPreviewFromFile(file) {
    const img      = document.getElementById('imgPreview');
    const thumbBox = document.getElementById('imgFileThumb');
    this._revokePreview();
    if (window.createImageBitmap) {
      try {
        const bitmap = await createImageBitmap(file, { resizeWidth: 200, resizeQuality: 'low' });
        const canvas = document.createElement('canvas');
        canvas.width  = bitmap.width;
        canvas.height = bitmap.height;
        canvas.getContext('2d').drawImage(bitmap, 0, 0);
        if (bitmap.close) bitmap.close();
        const blob = await new Promise(res => canvas.toBlob(res, 'image/webp', 0.8));
        if (blob) {
          this._previewURL = URL.createObjectURL(blob);
          img.src = this._previewURL;
          thumbBox.classList.add('has-img');
          return;
        }
      } catch (e) { /* افتادن به مسیر جایگزین */ }
    }
    // جایگزین (مرورگر قدیمی): object URL مستقیم + decode غیرهمزمان
    this._previewURL = URL.createObjectURL(file);
    img.src = this._previewURL;
    thumbBox.classList.add('has-img');
    if (img.decode) img.decode().catch(() => {});
  },
  _revokePreview() {
    if (this._previewURL) { URL.revokeObjectURL(this._previewURL); this._previewURL = null; }
  },

  _showFileItem({ name, thumb }) {
    document.getElementById('imgFileName').textContent = name || 'تصویر';
    document.getElementById('imgFileSize').textContent = '';
    const thumbBox = document.getElementById('imgFileThumb');
    const img      = document.getElementById('imgPreview');
    if (thumb) { img.src = thumb; thumbBox.classList.add('has-img'); }
    else       { img.removeAttribute('src'); thumbBox.classList.remove('has-img'); }
    document.getElementById('imgFileItem').hidden = false;
    document.getElementById('imgUploadZone').style.display = 'none';
  },
  _setFileProgress(pct, loaded, total) {
    document.getElementById('imgFileBar').style.width  = pct + '%';
    document.getElementById('imgFilePct').textContent  = pct + '%';
    if (total) {
      document.getElementById('imgFileSize').textContent =
        `${this._fmtBytes(loaded)} / ${this._fmtBytes(total)}`;
    }
  },
  _setFileState(state) {
    const item = document.getElementById('imgFileItem');
    item.classList.remove('is-uploading', 'is-done', 'is-error');
    item.classList.add('is-' + state);
    if (state === 'done') {
      // در حالت کامل فقط حجم نهایی را نشان بده (نه «خوانده/کل»)
      const sub = document.getElementById('imgFileSize');
      if (sub.textContent.includes('/')) sub.textContent = sub.textContent.split('/').pop().trim();
    }
  },
  _fileError(msg) {
    this._setFileState('error');
    document.getElementById('imgFileSize').textContent = msg;
    Toast.show(msg, 'error');
  },
  _showExistingImage(path, thumb) {
    this._showFileItem({ name: this._basename(path), thumb: thumb || path });
    document.getElementById('imgFileSize').textContent = 'تصویر فعلی';
    this._setFileState('done');
  },
  _resetFileUI() {
    this._revokePreview();
    const item = document.getElementById('imgFileItem');
    item.hidden = true;
    item.classList.remove('is-uploading', 'is-done', 'is-error');
    document.getElementById('imgFileBar').style.width = '0';
    document.getElementById('imgFilePct').textContent = '0%';
    document.getElementById('imgFileSize').textContent = '';
    document.getElementById('imgFileName').textContent = '';
    const img = document.getElementById('imgPreview');
    img.removeAttribute('src');
    document.getElementById('imgFileThumb').classList.remove('has-img');
    document.getElementById('imgUploadZone').style.display = '';
    document.getElementById('imgFileInput').value = '';
  },
  removeImage() {
    if (this._xhr) { try { this._xhr.abort(); } catch (e) {} this._xhr = null; }
    this._markDirty();
    this._pendingImage  = null;
    this._pendingThumb  = null;
    this._existingImage = null;
    this._existingThumb = null;
    this._resetFileUI();
  },

  // ── Save ─────────────────────────────────────────────
  async save() {
    const title    = document.getElementById('nf-title').value.trim();
    const body     = RTE.getHTML();
    const isPublic = document.getElementById('nf-public').checked    ? '1' : '0';
    const allUsers = document.getElementById('nf-all-users').checked ? '1' : '0';
    const badges   = [...document.querySelectorAll('.badge-check-cb:checked')].map(c => c.value);

    // ترکیب date+time و تبدیل به UTC برای ذخیره صحیح صرف‌نظر از timezone سرور
    const expiresDate = document.getElementById('nf-expires-date').value;
    const expiresTime = document.getElementById('nf-expires-time').value || '00:00';
    let expires = '';
    if (expiresDate) {
      const localDt = new Date(`${expiresDate}T${expiresTime}:00`);
      if (!isNaN(localDt.getTime())) {
        expires = localDt.toISOString().slice(0, 16); // "YYYY-MM-DDTHH:MM" در UTC
      }
    }

    if (!title) { Toast.show('عنوان الزامی است', 'error'); return; }
    if (RTE.plainLength() > RTE.MAX_CHARS) {
      Toast.show(`متن اعلان نباید بیشتر از ${RTE.MAX_CHARS.toLocaleString('fa-IR')} کاراکتر باشد`, 'error');
      return;
    }

    let imagePath = '';
    let thumbPath = '';
    if (this._pendingImage) {
      imagePath = this._pendingImage;
      thumbPath = this._pendingThumb || '';
    } else if (this._editId && this._existingImage) {
      imagePath = this._existingImage;
      thumbPath = this._existingThumb || '';
    }

    const btn = document.getElementById('notifSaveBtn');
    btn.disabled = true;

    const payload = { title, body, image_path: imagePath, thumbnail_path: thumbPath,
                      is_public: isPublic, target_all_users: allUsers,
                      expires_at: expires, badges };
    const action  = this._editId ? 'update_notification' : 'create_notification';
    if (this._editId) payload.id = this._editId;

    const res = await apiCall(action, payload);
    btn.disabled = false;

    if (res.ok) {
      const wasCreate = !this._editId;
      this._dirty = false;
      this.closeForm(true);
      Toast.show(this._editId ? 'اعلان ویرایش شد' : 'اعلان ایجاد شد');
      if (wasCreate) this._page = 1;
      await this.load();
    } else {
      Toast.show(res.msg || 'خطا در ذخیره', 'error');
    }
  },

  // ── Confirm dialog (عمومی: حذف / بستن فرم) ─────────────
  _askCb: null,
  _defaultConfirmIcon:
    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">' +
      '<polyline points="3 6 5 6 21 6"/>' +
      '<path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/>' +
      '<path d="M10 11v6M14 11v6M9 6V4a1 1 0 011-1h4a1 1 0 011 1v2"/>' +
    '</svg>',
  _ask({ title, heading, desc, icon = null, type = 'danger', btnLabel = 'تایید', onConfirm }) {
    this._askCb = onConfirm || null;
    document.getElementById('notifConfirmTitle').textContent   = title;
    document.getElementById('notifConfirmHeading').textContent = heading;
    document.getElementById('notifConfirmDesc').innerHTML      = desc;
    const ic = document.getElementById('notifConfirmIcon');
    ic.className = `confirm-icon ${type}`;
    ic.innerHTML = icon || this._defaultConfirmIcon;
    const btn = document.getElementById('notifConfirmBtn');
    btn.className   = `btn btn-sm ${type === 'warning' ? 'btn-warning' : 'btn-danger'}`;
    btn.textContent = btnLabel;
    btn.disabled    = false;
    this._openModal('notifConfirmModal');
  },
  async _runAsk() {
    const btn = document.getElementById('notifConfirmBtn');
    btn.disabled = true;
    try { if (this._askCb) await this._askCb(); }
    finally { btn.disabled = false; }
  },

  // ── Delete ────────────────────────────────────────────
  openDelete(id, name) {
    this._deleteId = id;
    this._ask({
      title:    'حذف اعلان',
      heading:  'آیا از حذف این اعلان اطمینان دارید؟',
      desc:     `اعلان «<span class="item-name">${this._esc(name)}</span>» برای همه کاربران به‌طور دائم حذف می‌شود و قابل بازگردانی نیست.`,
      type:     'danger',
      btnLabel: 'حذف اعلان',
      onConfirm: () => this.confirmDelete(),
    });
  },
  closeConfirm() { this._closeModal('notifConfirmModal'); this._askCb = null; this._deleteId = null; },
  async confirmDelete() {
    if (!this._deleteId) return;
    const res = await apiCall('delete_notification', { id: this._deleteId });
    if (res.ok) { this.closeConfirm(); Toast.show('اعلان حذف شد'); await this.load(); }
    else        { Toast.show(res.msg || 'خطا در حذف', 'error'); }
  },

  _openModal(id)  { document.getElementById(id).classList.add('open');    document.body.style.overflow = 'hidden'; },
  _closeModal(id) {
    document.getElementById(id).classList.remove('open');
    // اگر مودال دیگری هنوز باز است (تایید روی فرم)، قفل اسکرول را نگه دار
    if (!document.querySelector('.modal-overlay.open')) document.body.style.overflow = '';
  },
  _esc(str)     { return String(str ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); },
  _stripTags(html) {
    const tmp = document.createElement('div');
    tmp.innerHTML = String(html ?? '');
    return (tmp.textContent || tmp.innerText || '').replace(/\s+/g, ' ').trim();
  },
  _escAttr(str) { return String(str ?? '').replace(/'/g,"\\'"); },
};

// Drag & Drop
(function initDragDrop() {
  const zone = document.getElementById('imgUploadZone');
  zone.addEventListener('dragover',  e => { e.preventDefault(); zone.classList.add('drag-over'); });
  zone.addEventListener('dragleave', () => zone.classList.remove('drag-over'));
  zone.addEventListener('drop', e => {
    e.preventDefault(); zone.classList.remove('drag-over');
    const file = e.dataTransfer?.files?.[0];
    if (file) NM.handleFileSelect(file);
  });
})();

document.querySelectorAll('.modal-overlay').forEach(o => {
  o.addEventListener('click', e => {
    if (e.target !== o) return;
    if (o.id === 'notifFormModal')    NM.closeForm();
    if (o.id === 'notifConfirmModal') NM.closeConfirm();
  });
});
document.addEventListener('keydown', e => {
  if (e.key !== 'Escape') return;
  const open = document.querySelectorAll('.modal-overlay.open');
  if (!open.length) return;
  const top = open[open.length - 1];   // آخرین = بالاترین
  if (top.id === 'notifConfirmModal')   NM.closeConfirm();
  else if (top.id === 'notifFormModal') NM.closeForm();
});

// ── CustomSelect: ارتقای <select>های بومی به dropdown هماهنگ با تم ──
// این صفحه admin.js را بارگذاری نمی‌کند، پس همان enhancer هم‌کلاس را اینجا داریم
// تا dropdownهایش با بقیه پنل (settings/users) یکسان شوند (radius/آیتم/هاور/انتخاب).
const CSelect = {
  enhanceAll(root = document) { root.querySelectorAll('select:not([data-cs])').forEach(sel => this.enhance(sel)); },
  enhance(sel) {
    sel.dataset.cs = '1';
    sel.style.display = 'none';
    const wrap = document.createElement('div');
    wrap.className = 'cselect';
    const trigger = document.createElement('button');
    trigger.type = 'button';
    trigger.className = 'cselect-trigger';
    trigger.innerHTML = '<span class="cselect-value"></span>'
      + '<svg class="cselect-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>';
    const menu = document.createElement('div');
    menu.className = 'cselect-menu';
    menu.setAttribute('role', 'listbox');
    Array.from(sel.options).forEach(opt => {
      const item = document.createElement('div');
      item.className = 'cselect-option';
      item.setAttribute('role', 'option');
      item.dataset.value = opt.value;
      item.textContent = opt.textContent;
      item.addEventListener('click', () => {
        sel.value = opt.value;
        this._sync(sel);
        sel.dispatchEvent(new Event('change', { bubbles: true }));
        wrap.classList.remove('open');
      });
      menu.appendChild(item);
    });
    trigger.addEventListener('click', e => {
      e.stopPropagation();
      const isOpen = wrap.classList.contains('open');
      document.querySelectorAll('.cselect.open').forEach(w => w.classList.remove('open'));
      if (!isOpen) wrap.classList.add('open');
    });
    wrap.appendChild(trigger);
    wrap.appendChild(menu);
    sel.parentNode.insertBefore(wrap, sel.nextSibling);
    sel._csWrap = wrap;
    this._sync(sel);
  },
  refresh(sel) { if (sel && sel._csWrap) this._sync(sel); },
  _sync(sel) {
    const wrap = sel._csWrap; if (!wrap) return;
    const label = sel.options[sel.selectedIndex] ? sel.options[sel.selectedIndex].textContent : '';
    wrap.querySelector('.cselect-value').textContent = label;
    wrap.querySelectorAll('.cselect-option').forEach(o => o.classList.toggle('selected', o.dataset.value === sel.value));
  },
};
document.addEventListener('click', () => document.querySelectorAll('.cselect.open').forEach(w => w.classList.remove('open')));

document.addEventListener('DOMContentLoaded', () => { RTE.init(); NM._initDirty(); NM._initPerPage(); CSelect.enhanceAll(); NM.load(); });

// ── افکت ripple (موج کلیک) — این صفحه admin.js را لود نمی‌کند پس هندلر اینجا تکرار می‌شود ──
(function () {
  const SEL = '.btn, .hdr-btn, .btn-icon, .notif-row, .nm-adv-toggle,'
    + ' .cselect-option, .pg-btn, .nm-pag-btn, .modal-close, .notif-search-clear';
  document.addEventListener('pointerdown', function (e) {
    const btn = e.target.closest(SEL);
    if (!btn || btn.disabled || btn.getAttribute('aria-disabled') === 'true') return;
    const rect = btn.getBoundingClientRect();
    const size = Math.max(rect.width, rect.height);
    const r = document.createElement('span');
    r.className = 'ripple';
    r.style.width = r.style.height = size + 'px';
    r.style.left = (e.clientX - rect.left - size / 2) + 'px';
    r.style.top  = (e.clientY - rect.top  - size / 2) + 'px';
    btn.appendChild(r);
    r.addEventListener('animationend', () => r.remove());
  });
  // ناوبری لینک‌های هدر را ~160ms نگه می‌داریم تا ریپل دیده شود (prerender فوری است)
  document.addEventListener('click', function (e) {
    const a = e.target.closest(SEL);
    if (!a || a.tagName !== 'A') return;
    const href = a.getAttribute('href');
    if (!href || href.charAt(0) === '#' || a.target === '_blank') return;
    if (e.defaultPrevented || e.metaKey || e.ctrlKey || e.shiftKey || e.button) return;
    e.preventDefault();
    setTimeout(function () { window.location.href = href; }, 160);
  });
})();

// هدر چسبان هنگام اسکرول (مشترک با theme.js): .is-stuck با اسکرول به پایین
(function () {
  const header = document.querySelector('.app-header');
  if (!header) return;
  let ticking = false;
  function update() {
    header.classList.toggle('is-stuck', window.scrollY > 4);
    ticking = false;
  }
  window.addEventListener('scroll', function () {
    if (!ticking) { requestAnimationFrame(update); ticking = true; }
  }, { passive: true });
  update();
})();
