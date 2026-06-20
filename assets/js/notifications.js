    /* ── Theme: فقط جلوگیری از فلش اولیه (FOUC) ──
       سوییچ بدون لگ + همگام بین تب‌ها در theme.js انجام می‌شود. */
    (function () {
      const saved = localStorage.getItem('theme');
      const dark  = window.matchMedia('(prefers-color-scheme: dark)').matches;
      if (saved === 'dark' || (!saved && dark))
        document.documentElement.setAttribute('data-theme', 'dark');
    })();

    /* ── پاک‌سازی HTML اعلان در سمت کلاینت (دفاع لایه‌دوم) ──
       فقط تگ‌ها و ویژگی‌های امن مجاز هستند؛ هر چیز دیگری حذف می‌شود.
       متن اصلی روی سرور هم پاک‌سازی می‌شود؛ این فقط لایه احتیاطی است. */
    function sanitizeNotifHtml(html) {
      const ALLOWED_TAGS  = ['B','STRONG','I','EM','U','BR','P','DIV','SPAN','UL','OL','LI','A'];
      const ALLOWED_ATTRS = ['style','dir','href','target','rel'];
      const tpl = document.createElement('template');
      tpl.innerHTML = String(html ?? '');

      const walk = node => {
        [...node.childNodes].forEach(child => {
          if (child.nodeType === 1) { // element
            if (!ALLOWED_TAGS.includes(child.tagName)) {
              // تگ غیرمجاز: محتوای متنی‌اش را نگه دار، خود تگ را حذف کن
              const text = document.createTextNode(child.textContent || '');
              child.replaceWith(text);
              return;
            }
            [...child.attributes].forEach(attr => {
              const name = attr.name.toLowerCase();
              if (!ALLOWED_ATTRS.includes(name)) { child.removeAttribute(attr.name); return; }
              if (name === 'style') {
                // فقط چند ویژگی استایل امن
                const safe = [];
                child.getAttribute('style').split(';').forEach(decl => {
                  const [k, v] = decl.split(':').map(s => (s || '').trim().toLowerCase());
                  if (!k || !v) return;
                  if (/url\(|expression|javascript:/i.test(v)) return;
                  if (['text-align','color','background-color','font-weight','font-style','text-decoration','direction'].includes(k)) {
                    safe.push(`${k}:${v}`);
                  }
                });
                if (safe.length) child.setAttribute('style', safe.join(';'));
                else child.removeAttribute('style');
              }
              if (name === 'href') {
                const v = child.getAttribute('href') || '';
                if (!/^(https?:|mailto:|\/)/i.test(v.trim())) child.removeAttribute('href');
              }
            });
            if (child.tagName === 'A') { child.setAttribute('target','_blank'); child.setAttribute('rel','noopener noreferrer'); }
            walk(child);
          } else if (child.nodeType !== 3) {
            child.remove(); // کامنت و غیره
          }
        });
      };
      walk(tpl.content);
      return tpl.innerHTML;
    }

    /* ── Notification Panel ── */
    const NP = {

      // ── خواندن نگاشت {id: read_ts} با سازگاری با فرمت قدیمی (آرایه) ──
      _getGuestReadMap() {
        try {
          const raw = localStorage.getItem('notif_read_ids');
          if (!raw) return {};
          const parsed = JSON.parse(raw);
          if (Array.isArray(parsed)) {
            const map = {};
            parsed.forEach(id => { map[id] = 0; });
            return map;
          }
          return (parsed && typeof parsed === 'object') ? parsed : {};
        } catch { return {}; }
      },

      _setGuestReadMap(map) {
        try {
          let entries = Object.entries(map);
          if (entries.length > 80) entries = entries.slice(entries.length - 80);
          localStorage.setItem('notif_read_ids', JSON.stringify(Object.fromEntries(entries)));
        } catch { /* silent */ }
      },

      // ── اعمال وضعیت خوانده‌شده مهمان روی ردیف‌ها ──────
      // سه حالت ممکن: نخوانده‌شده / خوانده‌شده اما ویرایش‌شده / خوانده‌شده و فعلی
      initGuestReadState() {
        try {
          const map = this._getGuestReadMap();
          Object.keys(NOTIFS).forEach(id => {
            const n = NOTIFS[id];
            if (!n) return;

            const readTs = map[id];
            if (readTs === undefined) return;   // هرگز خوانده نشده → تگ «جدید» بماند

            const updatedTs = n.updated_at ? Math.floor(new Date(n.updated_at).getTime() / 1000) : 0;
            const isCurrent = (readTs === 0 || readTs >= updatedTs);

            const row = document.querySelector(`.notif-row[data-id="${id}"]`);
            if (!row) return;
            const unreadPill = row.querySelector('.npill-unread');

            if (isCurrent) {
              // خوانده‌شده و فعلی: حذف کامل تگ
              n.is_read   = true;
              n.is_edited = false;
              row.classList.remove('unread');
              if (unreadPill) unreadPill.remove();
            } else {
              // خوانده‌شده اما بعد از آن ویرایش شده: تغییر تگ به «ویرایش شده»
              n.is_read   = false;
              n.is_edited = true;
              if (unreadPill) {
                unreadPill.className   = 'npill npill-edited';
                unreadPill.textContent = 'ویرایش شده';
              }
            }
          });
        } catch { /* silent */ }
      },

      open(id) {
        const n = NOTIFS[id];
        if (!n) return;

        // وضعیت قبل از علامت‌گذاری — برای نمایش در پیلز مودال
        const wasEdited = !!n.is_edited;

        // ── علامت‌گذاری خوانده‌شده (هم «جدید» هم «ویرایش شده») ───
        if (!n.is_read) {
          n.is_read   = true;
          n.is_edited = false;
          // بروزرسانی ظاهر ردیف
          const row = document.querySelector(`.notif-row[data-id="${id}"]`);
          if (row) {
            row.classList.remove('unread');
            const pill = row.querySelector('.npill-unread, .npill-edited');
            if (pill) pill.remove();
          }
          // لاگین‌کرده: API | مهمان: localStorage
          if (IS_LOGGED_IN) {
            fetch('api.php?action=mark_read', {
              method:  'POST',
              headers: { 'Content-Type': 'application/json' },
              body:    JSON.stringify({ notification_id: id }),
            }).catch(() => {});
          } else {
            try {
              const map = this._getGuestReadMap();
              map[id] = Math.floor(Date.now() / 1000);
              this._setGuestReadMap(map);
            } catch { /* silent */ }
          }
        }

        // عنوان
        document.getElementById('ndTitle').textContent = n.title || '';

        // متن (HTML غنی — پاک‌سازی‌شده در سمت سرور، دوباره در سمت کلاینت)
        const textEl = document.getElementById('ndText');
        if (n.body) {
          textEl.innerHTML     = sanitizeNotifHtml(n.body);
          textEl.style.display = 'block';
        } else {
          textEl.style.display = 'none';
          textEl.innerHTML     = '';
        }

        // تصویر — بارگذاری پیشرونده (thumbnail → full)
        const imgWrap = document.getElementById('ndImageWrap');
        const img     = document.getElementById('ndImage');
        if (n.image) {
          imgWrap.style.display = 'block';
          imgWrap.classList.add('img-loading');
          img.alt           = n.title || '';
          img.style.cssText = '';
          img.dataset.full  = n.image;   // مبنای نمایش تمام‌صفحه (lightbox)

          if (n.thumbnail) {
            // thumbnail موجود: فوری نشان بده (blurred)
            img.src             = n.thumbnail;
            img.style.filter    = 'blur(10px)';
            img.style.transform = 'scale(1.04)';
          } else {
            // بدون thumbnail: img مخفی — shimmer دیده می‌شود
            img.src           = '';
            img.style.display = 'none';
          }

          // لود تصویر اصلی در پس‌زمینه
          const loader   = new Image();
          loader.onload  = async () => {
            try { await loader.decode(); } catch {}
            img.style.display   = '';
            img.src             = n.image;
            img.style.filter    = '';
            img.style.transform = '';
            imgWrap.classList.remove('img-loading');
          };
          loader.onerror = () => {
            imgWrap.classList.remove('img-loading');
            img.style.display = '';
            if (!n.thumbnail) imgWrap.style.display = 'none';
          };
          loader.src = n.image;
        } else {
          imgWrap.style.display = 'none';
          img.src               = '';
          img.style.cssText     = '';
          delete img.dataset.full;
        }

        // متادیتا
        this._buildMeta(n, wasEdited);

        // نمایش
        const overlay = document.getElementById('ndOverlay');
        overlay.classList.add('open');
        document.body.style.overflow = 'hidden';
        // فوکوس روی خود کادر (نه دکمه ضربدر) تا کادر فوکوس روی ✕ نیفتد،
        // ولی Escape و دسترسی‌پذیری حفظ شود.
        const box = overlay.querySelector('.nd-box');
        if (box) box.focus({ preventScroll: true });
      },

      close() {
        document.getElementById('ndOverlay').classList.remove('open');
        document.body.style.overflow = '';
        // پاکسازی state بارگذاری پیشرونده
        const img     = document.getElementById('ndImage');
        const imgWrap = document.getElementById('ndImageWrap');
        if (img)     { img.src = ''; img.style.cssText = ''; delete img.dataset.full; }
        if (imgWrap) imgWrap.classList.remove('img-loading');
      },

      _buildMeta(n, wasEdited) {
        const meta = document.getElementById('ndMeta');
        meta.innerHTML = '';

        // تاریخ ایجاد
        const created = new Date(n.created_at);
        const dateRow = this._metaRow(
          '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
          created.toLocaleString('fa-IR')
        );
        meta.appendChild(dateRow);

        // تاریخ انقضا
        if (n.expires_at) {
          const exp = new Date(n.expires_at * 1000);
          const expRow = this._metaRow(
            '<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>',
            (n.is_expired ? 'منقضی شد: ' : 'انقضا: ') + exp.toLocaleString('fa-IR')
          );
          if (n.is_expired) expRow.classList.add('expired-row');
          meta.appendChild(expRow);
        }

        // pills (public + badges)
        const pills = [];
        if (n.is_public)  pills.push({ text: 'عمومی', cls: 'npill-public' });
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

    /* ── بستن modal ── */
    document.getElementById('ndCloseBtn').addEventListener('click',    () => NP.close());
    document.getElementById('ndCloseAction').addEventListener('click', () => NP.close());

    document.getElementById('ndOverlay').addEventListener('click', e => {
      if (e.target === e.currentTarget) NP.close();
    });

    document.addEventListener('keydown', e => {
      if (e.key === 'Escape' && document.getElementById('ndOverlay').classList.contains('open')) {
        NP.close();
      }
    });

    // برای مهمان: اعمال وضعیت خوانده‌شده از localStorage روی ردیف‌ها
    if (!IS_LOGGED_IN) NP.initGuestReadState();

    // ── Hover preload: لود تصویر هنگام hover روی ردیف ──────
    // وقتی mouse روی ردیف می‌رود، لود تصویر شروع می‌شود تا
    // هنگام کلیک "مشاهده" از cache سرو شود
    document.querySelectorAll('.notif-row[data-id]').forEach(row => {
      let timer;
      row.addEventListener('mouseenter', () => {
        timer = setTimeout(() => {
          const n = NOTIFS[parseInt(row.dataset.id)];
          if (n?.image && !n._preloaded) {
            n._preloaded = true;
            // preload هر دو نسخه
            if (n.thumbnail) new Image().src = n.thumbnail;
            new Image().src = n.image;
          }
        }, 120); // تاخیر کوتاه تا از hover تصادفی جلوگیری شود
      }, { passive: true });
      row.addEventListener('mouseleave', () => clearTimeout(timer), { passive: true });
    });

/* ══ پنل جستجوی پیشرفته + custom selectها (بلوک دوم پیشین) ══ */
    /* باز/بستن پنل جستجوی پیشرفته */
    (function () {
      const btn   = document.getElementById('notifAdvToggle');
      const panel = document.getElementById('notifAdvPanel');
      if (!btn || !panel) return;
      btn.addEventListener('click', () => {
        const open = panel.classList.toggle('open');
        btn.classList.toggle('active', open);
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
      });
    })();

    /* جلوگیری از جستجو وقتی باکس جستجو خالی است (و فیلتری هم فعال نیست) */
    (function () {
      const form = document.querySelector('.notif-search-form');
      if (!form) return;
      const q = document.getElementById('notif-q');
      const val = el => (el && el.value ? el.value.trim() : '');
      form.addEventListener('submit', e => {
        // فقط دکمه جستجو (و Enter داخل فرم) را گارد می‌کنیم؛
        // دکمه «اعمال فیلتر» و تغییر تعداد در هر صفحه دست‌نخورده می‌ماند.
        const submitter = e.submitter;
        const isFilterApply = submitter && submitter.classList.contains('notif-adv-apply');
        if (isFilterApply) return;
        const hasText    = val(q) !== '';
        const hasFilters = val(document.getElementById('adv-df')) !== '' ||
                           val(document.getElementById('adv-dt')) !== '' ||
                           val(document.getElementById('adv-st')) !== '';
        if (!hasText && !hasFilters) {
          e.preventDefault();
          if (q) q.focus();
        }
      });
    })();

    /* dropdown سفارشی: لیست بومی select را با لیستی هماهنگ با تم پروژه جایگزین می‌کند
       (select اصلی برای ارسال فرم و دسترس‌پذیری در DOM می‌ماند) */
    (function () {
      const CHEV = '<svg class="cselect-chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>';

      function init(select) {
        const wrap = document.createElement('div');
        wrap.className = 'cselect';
        select.parentNode.insertBefore(wrap, select);
        wrap.appendChild(select);
        select.classList.add('cselect-native');
        select.tabIndex = -1;
        select.setAttribute('aria-hidden', 'true');

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'cselect-btn';
        btn.setAttribute('aria-haspopup', 'listbox');
        btn.setAttribute('aria-expanded', 'false');
        if (select.getAttribute('aria-label')) btn.setAttribute('aria-label', select.getAttribute('aria-label'));
        btn.innerHTML = '<span class="cselect-label"></span>' + CHEV;
        const label = btn.querySelector('.cselect-label');

        const menu = document.createElement('div');
        menu.className = 'cselect-menu';
        menu.setAttribute('role', 'listbox');

        Array.from(select.options).forEach((opt, i) => {
          const item = document.createElement('div');
          item.className = 'cselect-opt';
          item.setAttribute('role', 'option');
          item.textContent = opt.textContent;
          item.setAttribute('aria-selected', opt.selected ? 'true' : 'false');
          if (opt.selected) label.textContent = opt.textContent;
          item.addEventListener('click', () => choose(i));
          menu.appendChild(item);
        });
        if (!label.textContent) label.textContent = (select.options[select.selectedIndex] || {}).textContent || '';

        wrap.appendChild(btn);
        wrap.appendChild(menu);

        function open()  { closeAll(); wrap.classList.add('open');  btn.setAttribute('aria-expanded', 'true'); }
        function close() { wrap.classList.remove('open'); btn.setAttribute('aria-expanded', 'false'); }
        function choose(i) {
          select.selectedIndex = i;
          label.textContent = select.options[i].textContent;
          menu.querySelectorAll('.cselect-opt').forEach((el, k) => el.setAttribute('aria-selected', k === i ? 'true' : 'false'));
          close();
          // change → onchange بومی (مثلا submit خودکار «تعداد در هر صفحه») را فعال می‌کند
          select.dispatchEvent(new Event('change', { bubbles: true }));
        }

        btn.addEventListener('click', (e) => { e.preventDefault(); wrap.classList.contains('open') ? close() : open(); });
        btn.addEventListener('keydown', (e) => {
          if (e.key === 'ArrowDown' || e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(); }
          else if (e.key === 'Escape') close();
        });
        wrap._close = close;
      }

      function closeAll() {
        document.querySelectorAll('.cselect.open').forEach((w) => w._close && w._close());
      }

      document.querySelectorAll('select[data-cselect]').forEach(init);
      document.addEventListener('click', (e) => { if (!e.target.closest('.cselect')) closeAll(); });
      document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeAll(); });
    })();
