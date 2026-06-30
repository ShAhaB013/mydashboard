    'use strict';

    /* ══ Toast صفحه + خطای روی فیلد (قاب قرمز) + ریست با تایپ ══ */
    const _toastWrap = document.getElementById('loginToastWrap');
    const _TOAST_ICON = {
      error:   '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>',
      success: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="M22 4L12 14.01l-3-3"/></svg>',
    };
    let _toastTimer = null;
    function showToast(msg, type) {
      type = type || 'error';
      if (!_toastWrap) return;
      _toastWrap.innerHTML = '';
      const t = document.createElement('div');
      t.className = 'login-toast ' + type;
      t.innerHTML = (_TOAST_ICON[type] || '') + '<span></span>';
      t.querySelector('span').textContent = msg;
      _toastWrap.appendChild(t);
      clearTimeout(_toastTimer);
      _toastTimer = setTimeout(() => { t.classList.add('hide'); setTimeout(() => t.remove(), 250); }, 4500);
    }
    function _fieldComp(id) { const el = document.getElementById(id); return (el && el.closest) ? el.closest('.field') : null; }
    function _fieldWrap(id) { const el = document.getElementById(id); return el ? (el.closest('.login-input-wrap') || el) : null; }
    function markFieldError(id) { const w = _fieldWrap(id); if (w) w.classList.add('has-error'); }
    function clearFieldError(id) {
      // فیلدهای کامپوننت جدید (.field) → پاکسازی با helper مشترک
      if (_fieldComp(id) && window.Field) { window.Field.clear(id); return; }
      const w = _fieldWrap(id); if (w) w.classList.remove('has-error');
    }
    function clearAllErrors(form) {
      if (!form) return;
      form.querySelectorAll('.has-error').forEach(e => e.classList.remove('has-error'));
      if (window.Field) form.querySelectorAll('.field[data-state="error"]').forEach(f => {
        const inp = f.querySelector('.field-input'); if (inp) window.Field.clear(inp);
      });
    }
    /* علامت‌گذاری فیلد + پیام + فوکوس؛ همیشه false برمی‌گرداند تا در شرط‌ها به‌راحتی return شود. */
    function failField(id, msg) {
      if (_fieldComp(id) && window.Field) {
        window.Field.set(id, 'error', msg);
      } else {
        markFieldError(id);
        showToast(msg, 'error');
      }
      const el = document.getElementById(id);
      if (el) el.focus();
      return false;
    }

    // ریست خطای هر باکس به‌محض تایپ کاربر در همان باکس
    ['loginUsername', 'loginPassword'].forEach(id => {
      const el = document.getElementById(id);
      if (el) el.addEventListener('input', () => clearFieldError(id));
    });

    /* ── آیکن‌های چشم (نمایش/مخفی) ── */
    const EYE_SVG     = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
    const EYE_OFF_SVG = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/></svg>';

    /* ── نمایش/مخفی کردن رمز ── */
    function togglePass(inputId, btn) {
      const input = document.getElementById(inputId);
      if (!input) return;
      const willShow = input.type === 'password';
      input.type = willShow ? 'text' : 'password';
      btn.innerHTML = willShow ? EYE_OFF_SVG : EYE_SVG;
    }

    /* ══ ارسال فرم ورود ══ */
    function setLoading(btn, on, idleLabel) {
      btn.classList.toggle('loading', on);
      btn.disabled = on;
      const lbl = btn.querySelector('.login-btn-label');
      if (lbl && idleLabel && !on) lbl.textContent = idleLabel;
    }

    async function submitAuth(action, payload, btn, idleLabel, errFields) {
      setLoading(btn, true);
      try {
        const res  = await fetch('api.php?action=' + action, {
          method:  'POST',
          headers: { 'Content-Type': 'application/json' },
          body:    JSON.stringify(payload),
        });
        const data = await res.json();
        if (data.ok) {
          btn.classList.remove('loading');
          btn.classList.add('success'); // همان دکمه: سبز می‌شود و تیک می‌خورد
          setTimeout(() => window.location.replace('index.php'), 700);
          return;
        }
        (errFields || []).forEach(markFieldError);
        showToast(data.msg || 'خطایی رخ داد', 'error');
      } catch (err) {
        showToast('خطا در ارتباط با سرور', 'error');
      }
      setLoading(btn, false, idleLabel);
    }

    /* ورود */
    const loginForm = document.getElementById('loginForm');
    loginForm.addEventListener('submit', (e) => {
      e.preventDefault();
      const username = document.getElementById('loginUsername').value.trim();
      const password = document.getElementById('loginPassword').value;
      if (!username) return failField('loginUsername', 'نام کاربری الزامی است');
      if (!password) return failField('loginPassword', 'رمز عبور الزامی است');
      submitAuth('login', { username, password },
                 document.getElementById('loginSubmitBtn'), 'ورود', ['loginUsername', 'loginPassword']);
    });
