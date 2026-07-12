    /* ── Theme: only prevents the initial flash (FOUC) ──
       Lag-free switching + cross-tab sync are handled in theme.js. */
    (function () {
      const saved = localStorage.getItem('theme');
      const dark  = window.matchMedia('(prefers-color-scheme: dark)').matches;
      if (saved === 'dark' || (!saved && dark))
        document.documentElement.setAttribute('data-theme', 'dark');
    })();

    /* ── Client-side sanitization of notification HTML (second defense layer) ──
       Only safe tags and attributes are allowed; everything else is stripped.
       The source text is also sanitized server-side; this is just a precautionary layer. */
    function sanitizeNotifHtml(html) {
      const ALLOWED_TAGS  = ['B','STRONG','I','EM','U','BR','P','DIV','SPAN','UL','OL','LI','A'];
      const ALLOWED_ATTRS = ['style','dir','href','target','rel'];
      const tpl = document.createElement('template');
      tpl.innerHTML = String(html ?? '');

      const walk = node => {
        [...node.childNodes].forEach(child => {
          if (child.nodeType === 1) { // element
            if (!ALLOWED_TAGS.includes(child.tagName)) {
              // Disallowed tag: keep its text content, remove the tag itself
              const text = document.createTextNode(child.textContent || '');
              child.replaceWith(text);
              return;
            }
            [...child.attributes].forEach(attr => {
              const name = attr.name.toLowerCase();
              if (!ALLOWED_ATTRS.includes(name)) { child.removeAttribute(attr.name); return; }
              if (name === 'style') {
                // Only a handful of safe style properties
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
            child.remove(); // comments and the like
          }
        });
      };
      walk(tpl.content);
      return tpl.innerHTML;
    }

    /* ── Notification Panel ── */
    const NP = {

      // ── Reads the {id: read_ts} map, with compatibility for the old (array) format ──
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

      // ── Applies the guest's read state to the rows ──────
      // Three possible states: unread / read but since edited / read and current
      initGuestReadState() {
        try {
          const map = this._getGuestReadMap();
          Object.keys(NOTIFS).forEach(id => {
            const n = NOTIFS[id];
            if (!n) return;

            const readTs = map[id];
            if (readTs === undefined) return;   // never read → keep the "new" tag

            const updatedTs = n.updated_at ? Math.floor(new Date(n.updated_at).getTime() / 1000) : 0;
            const isCurrent = (readTs === 0 || readTs >= updatedTs);

            const row = document.querySelector(`.notif-row[data-id="${id}"]`);
            if (!row) return;
            const unreadPill = row.querySelector('.npill-unread');

            if (isCurrent) {
              // Read and current: remove the tag entirely
              n.is_read   = true;
              n.is_edited = false;
              row.classList.remove('unread');
              if (unreadPill) unreadPill.remove();
            } else {
              // Read, but edited since: change the tag to "edited"
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

        // State before marking — for display in the modal's pills
        const wasEdited = !!n.is_edited;

        // ── Mark as read (covers both "new" and "edited") ───
        if (!n.is_read) {
          n.is_read   = true;
          n.is_edited = false;
          // Update the row's appearance
          const row = document.querySelector(`.notif-row[data-id="${id}"]`);
          if (row) {
            row.classList.remove('unread');
            const pill = row.querySelector('.npill-unread, .npill-edited');
            if (pill) pill.remove();
          }
          // Logged in: API | guest: localStorage
          if (IS_LOGGED_IN) {
            fetch('api.php?action=mark_read', {
              method:  'POST',
              headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.CSRF_TOKEN || '' },
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
        if (n.image) {
          imgWrap.style.display = 'block';
          imgWrap.classList.add('img-loading');
          img.alt           = n.title || '';
          img.style.cssText = '';
          img.dataset.full  = n.image;   // basis for full-screen display (lightbox)

          if (n.thumbnail) {
            // Thumbnail available: show it immediately (blurred)
            img.src             = n.thumbnail;
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

        this._buildMeta(n, wasEdited);

        // Show
        const overlay = document.getElementById('ndOverlay');
        const ndBody = overlay.querySelector('.nd-body');
        if (ndBody) ndBody.scrollTop = 0;
        overlay.classList.add('open');
        document.body.style.overflow = 'hidden';
        // Focus the box itself (not the close button) so focus doesn't land on
        // the ✕, while still preserving Escape and accessibility.
        const box = overlay.querySelector('.nd-box');
        if (box) box.focus({ preventScroll: true });
      },

      close() {
        document.getElementById('ndOverlay').classList.remove('open');
        document.body.style.overflow = '';
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

    /* ── Close modal ── */
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

    // For guests: apply the read state from localStorage to the rows
    if (!IS_LOGGED_IN) NP.initGuestReadState();

    /* ── Actions (replaces on* handlers, for CSP) ── */
    if (window.Actions) {
      Actions.register({
        npOpen:     (el) => NP.open(parseInt(el.dataset.id, 10)),
        submitForm: (el) => el.form && el.form.submit(),
        notifGotoStep: (el) => {
          const dir = parseInt(el.dataset.dir, 10);
          const inp = document.getElementById('notifGotoInput');
          if (!inp) return;
          const max = parseInt(inp.max, 10) || 1;
          const cur = parseInt(inp.value, 10);
          const base = Number.isFinite(cur) ? cur : 1;
          inp.value = Math.min(Math.max(1, base + dir), max);
          const form = document.getElementById('notifGotoForm');
          if (form) form.requestSubmit ? form.requestSubmit() : form.submit();
        },
      });
    }

    // ── Hover preload: loads the image on row hover ──────
    // When the mouse enters a row, image loading starts so it
    // can be served from cache when the user clicks "view"
    document.querySelectorAll('.notif-row[data-id]').forEach(row => {
      let timer;
      row.addEventListener('mouseenter', () => {
        timer = setTimeout(() => {
          const n = NOTIFS[parseInt(row.dataset.id)];
          if (n?.image && !n._preloaded) {
            n._preloaded = true;
            // Preload both versions
            if (n.thumbnail) new Image().src = n.thumbnail;
            new Image().src = n.image;
          }
        }, 120); // short delay to avoid accidental hovers
      }, { passive: true });
      row.addEventListener('mouseleave', () => clearTimeout(timer), { passive: true });
    });

/* ══ Advanced search panel + custom selects (former second block) ══ */
    /* Open/close the advanced search panel */
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

    /* Prevents searching when the search box is empty (and no filter is active either) */
    (function () {
      const form = document.querySelector('.notif-search-form');
      if (!form) return;
      const q = document.getElementById('notif-q');
      const val = el => (el && el.value ? el.value.trim() : '');
      form.addEventListener('submit', e => {
        // We only guard the search button (and Enter inside the form);
        // the "apply filter" button and per-page count change are left untouched.
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

    /* Custom dropdown: replaces the native select's list with one matching the project theme
       (the original select stays in the DOM for form submission and accessibility) */
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
          // change → triggers the native onchange (e.g. auto-submitting "items per page")
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
