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
      // فیلدهای کامپوننتِ جدید (.field) → پاکسازی با helperِ مشترک
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
    /* علامت‌گذاری فیلد + پیام + فوکوس؛ همیشه false برمی‌گرداند تا در شرط‌ها به‌راحتی return شود.
       فیلدهای .field پیامِ خطا را زیرِ خود نشان می‌دهند؛ بقیه toast می‌گیرند. */
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
    /* نگاشت نام فیلد سروری → id ورودی */
    const SERVER_FIELD_MAP = { full_name: 'regFullName', email: 'regEmail', password: 'regPassword' };

    // ریست خطای هر باکس به‌محض تایپ کاربر در همان باکس
    ['loginUsername', 'loginPassword', 'regFullName', 'regEmail', 'regPassword', 'regConfirm', 'regCode',
     'fpEmail', 'fpCode', 'fpPassword', 'fpConfirm'].forEach(id => {
      const el = document.getElementById(id);
      if (el) el.addEventListener('input', () => clearFieldError(id));
    });

    /* ── اعتبارسنجیِ زندهٔ فیلدهای کامپوننتِ .field (در کلِ فرم‌ها) ──
       حینِ تایپ اگر معتبر شد → success (سبز، بدون پیام)؛ هنگامِ خروج اگر نامعتبر بود → error (قرمز + پیام).
       همان رفتارِ بخشِ ثبت‌نام، برای ایمیل/نام/رمز/تکرارِ رمزِ همهٔ فرم‌ها. */
    if (window.Field) {
      const $ = (id) => document.getElementById(id);
      const setFocusIdle = (el) => {
        const f = el.closest('.field');
        if (f) f.setAttribute('data-state', document.activeElement === el ? 'focus' : 'idle');
      };
      const liveEmail = (id) => {
        const el = $(id); if (!el) return;
        // ایمیل: تاییدِ نهایی (دامنه/MX) با سرور است؛ سبزِ زودهنگام نده تا با ردِ سرور تداخل نکند.
        el.addEventListener('input', () => setFocusIdle(el));
        el.addEventListener('blur', () => {
          const v = el.value.trim();
          if (v && !regEmailValid(v)) Field.set(el, 'error', 'قالب ایمیل نامعتبر است');
        });
      };
      const liveName = (id) => {
        const el = $(id); if (!el) return;
        const ok = (v) => v.length >= 2 && regNameValid(v);
        el.addEventListener('input', () => {
          const v = el.value.trim();
          if (!v) return setFocusIdle(el);
          if (ok(v)) Field.set(el, 'success', 'درست است'); else setFocusIdle(el);
        });
        el.addEventListener('blur', () => {
          const v = el.value.trim();
          if (!v) return setFocusIdle(el);
          if (!ok(v)) Field.set(el, 'error', 'نام معتبر نیست؛ فقط حروف و حداقل ۲ کاراکتر');
          else Field.set(el, 'success', 'درست است');
        });
      };
      const checkConfirm = (id, srcId, onBlur) => {
        const el = $(id), src = $(srcId); if (!el || !src) return;
        const v = el.value;
        if (!v) return setFocusIdle(el);
        if (v === src.value) Field.set(el, 'success', 'یکسان است');
        else if (onBlur || document.activeElement !== el) Field.set(el, 'error', 'با رمز عبور یکسان نیست');
        else setFocusIdle(el);
      };
      const livePassword = (id, confirmId) => {
        const el = $(id); if (!el) return;
        el.addEventListener('input', () => {
          const v = el.value;
          if (!v) setFocusIdle(el);
          else if (pwMeetsPolicy(v)) Field.set(el, 'success', 'رمز مناسب است');
          else setFocusIdle(el);
          if (confirmId) checkConfirm(confirmId, id, false);   // تکرار را هم هماهنگ کن
        });
        el.addEventListener('blur', () => {
          const v = el.value;
          if (v && !pwMeetsPolicy(v)) Field.set(el, 'error', 'رمز ضعیف است؛ حداقل ۶ کاراکتر همراه حروف بزرگ، عدد یا نماد');
        });
      };
      const liveConfirm = (id, srcId) => {
        const el = $(id); if (!el) return;
        el.addEventListener('input', () => checkConfirm(id, srcId, false));
        el.addEventListener('blur',  () => checkConfirm(id, srcId, true));
      };

      liveName('regFullName');
      liveEmail('regEmail');
      liveEmail('fpEmail');
      livePassword('regPassword', 'regConfirm');
      livePassword('fpPassword', 'fpConfirm');
      liveConfirm('regConfirm', 'regPassword');
      liveConfirm('fpConfirm', 'fpPassword');
    }

    // فاصله مجاز ارسال مجدد کد (ثانیه) — از تنظیمات سرور در پاسخ API به‌روزرسانی می‌شود
    let RESEND_COOLDOWN = 30;

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

    /* ── تولید رمز تصادفی، قوی و یکتا (Web Crypto) ── */
    function generatePassword(passId, confirmId) {
      passId = passId || 'regPassword'; confirmId = confirmId || 'regConfirm';
      const U = 'ABCDEFGHJKLMNPQRSTUVWXYZ';   // بدون I,O مبهم
      const L = 'abcdefghijkmnopqrstuvwxyz';   // بدون l مبهم
      const D = '23456789';                    // بدون 0,1 مبهم
      const S = '!@#$%^&*-_=+?';
      const ALL = U + L + D + S;
      const rnd = (n) => { const a = new Uint32Array(1); crypto.getRandomValues(a); return a[0] % n; };

      const len = 14 + rnd(5); // طول ۱۴ تا ۱۸
      const out = [U[rnd(U.length)], L[rnd(L.length)], D[rnd(D.length)], S[rnd(S.length)]]; // حداقل یکی از هر دسته
      while (out.length < len) out.push(ALL[rnd(ALL.length)]);
      for (let i = out.length - 1; i > 0; i--) { const j = rnd(i + 1); [out[i], out[j]] = [out[j], out[i]]; } // درهم‌ریزی
      const pwd = out.join('');

      const p = document.getElementById(passId);
      const c = document.getElementById(confirmId);
      p.value = pwd; c.value = pwd;
      p.type = 'text'; // نمایش رمز تولیدشده تا کاربر ببیند/کپی کند
      const eye = p.parentElement.querySelector('.login-pass-toggle');
      if (eye) eye.innerHTML = EYE_OFF_SVG;
      updateRegStrength(pwd);
      p.focus();
    }

    /* ── سیاست و سنجش قدرت رمز (هم‌راستا با PasswordPolicy سرور) ── */
    function pwScore(val) {
      let s = 0;
      if (val.length >= 8)          s++;
      if (/[A-Z]/.test(val))        s++;
      if (/[0-9]/.test(val))        s++;
      if (/[^A-Za-z0-9]/.test(val)) s++;
      return s;
    }
    function pwMeetsPolicy(val) { return !!val && val.length >= 6 && pwScore(val) >= 2; }

    function updateRegStrength(val) {
      if (!val) { setLogoStrength(0); return; }
      const score = pwScore(val);
      const lvl = val.length < 6 ? 1 : (score || 1);
      setLogoStrength(lvl);
    }

    /* رنگ و انیمیشن لوگو متناسب با قدرت رمز (۰=پیش‌فرض، ۱..۴ سطوح) */
    function setLogoStrength(lvl) {
      const logo = document.querySelector('.login-logo');
      if (!logo) return;
      logo.classList.remove('pw-1', 'pw-2', 'pw-3', 'pw-4');
      if (lvl >= 1) logo.classList.add('pw-' + lvl);
    }

    /* ══ تعویض تب ورود/ثبت‌نام (انیمیشن نرم با transform) ══ */
    const tabs     = document.getElementById('loginTabs');
    const tabLogin = document.getElementById('tabLogin');
    const tabReg   = document.getElementById('tabRegister');
    const loginForm    = document.getElementById('loginForm');
    const registerForm = document.getElementById('registerForm');

    function switchMode(mode) {
      // در حالت بازیابی رمز، تب‌ها مخفی‌اند؛ اگر به‌هر دلیل کلیک رسید، نادیده بگیر
      const ff = document.getElementById('forgotForm');
      if (ff && !ff.hidden) return;
      if (tabs.dataset.mode === mode) return;
      tabs.dataset.mode = mode;
      const isLogin = mode === 'login';

      tabLogin.classList.toggle('active', isLogin);
      tabReg.classList.toggle('active', !isLogin);
      tabLogin.setAttribute('aria-selected', isLogin ? 'true' : 'false');
      tabReg.setAttribute('aria-selected', !isLogin ? 'true' : 'false');

      const show = isLogin ? loginForm : registerForm;
      const hide = isLogin ? registerForm : loginForm;
      hide.hidden = true;
      hide.classList.remove('anim-in');
      show.hidden = false;
      // ری‌استارت انیمیشن ورود فرم
      show.classList.remove('anim-in'); void show.offsetWidth; show.classList.add('anim-in');

      if (isLogin) setLogoStrength(0); // ریست رنگ لوگو هنگام بازگشت به ورود
      else showRegStep(1);             // ثبت‌نام همیشه از مرحله ۱ شروع شود

      // پاک‌سازی خطاها (قاب قرمز فیلدها) و فوکوس فیلد اول
      clearAllErrors(loginForm);
      clearAllErrors(registerForm);
      const first = show.querySelector('input');
      if (first) setTimeout(() => first.focus(), 60);

      // به‌روزرسانی hash بدون پرش
      history.replaceState(null, '', isLogin ? location.pathname : '#register');
    }

    tabLogin.addEventListener('click', () => switchMode('login'));
    tabReg.addEventListener('click',   () => switchMode('register'));

    // ورود مستقیم به تب ثبت‌نام از طریق #register
    if (location.hash === '#register' && tabs.dataset.mode !== 'register') switchMode('register');

    /* ══ ارسال فرم (مشترک برای ورود و ثبت‌نام) ══ */
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
    loginForm.addEventListener('submit', (e) => {
      e.preventDefault();
      const username = document.getElementById('loginUsername').value.trim();
      const password = document.getElementById('loginPassword').value;
      if (!username) return failField('loginUsername', 'نام کاربری الزامی است');
      if (!password) return failField('loginPassword', 'رمز عبور الزامی است');
      submitAuth('login', { username, password },
                 document.getElementById('loginSubmitBtn'), 'ورود', ['loginUsername', 'loginPassword']);
    });

    /* ══════════ ثبت‌نام سه‌مرحله‌ای ══════════ */
    function toFa(n) { return String(n).replace(/\d/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]); }
    let resendTimerId = null;

    /* نمایش مرحله n و به‌روزرسانی نشانگرها/دکمه‌ها */
    function showRegStep(n) {
      registerForm.dataset.step = n;
      registerForm.querySelectorAll('.reg-step').forEach(el => { el.hidden = (Number(el.dataset.step) !== n); });
      registerForm.querySelectorAll('.reg-seg').forEach((s, i) => s.classList.toggle('active', i < n));
      document.getElementById('regStepNum').textContent = toFa(n);
      document.getElementById('regBackBtn').hidden = (n === 1);
      registerForm.querySelector('#registerSubmitBtn .login-btn-label').textContent = (n === 3) ? 'تایید و ورود' : 'ادامه';
      clearAllErrors(registerForm);
      // لوگوی قدرت رمز فقط در مرحله ۲ معنا دارد
      if (n === 2) updateRegStrength(document.getElementById('regPassword').value);
      else setLogoStrength(0);
      // فوکوس اولین فیلد مرحله (فقط وقتی فرم دیده می‌شود)
      if (!registerForm.hidden) {
        const step = registerForm.querySelector('.reg-step[data-step="' + n + '"]');
        const first = step && step.querySelector('input');
        if (first) setTimeout(() => first.focus(), 70);
      }
    }

    const regEmailValid = (v) => /^[A-Za-z0-9._%+-]+@(?:[A-Za-z0-9-]+\.)+[A-Za-z]{2,}$/.test(v);
    // فقط حروف (فارسی/انگلیسی) + فاصله/خط‌تیره/آپاستروف — هم‌راستا با Validator::name سرور
    const regNameValid = (v) => /^[\p{L}\p{M}][\p{L}\p{M}\s'’-]*$/u.test(v);

    function validateRegStep1() {
      const n  = document.getElementById('regFullName').value.trim();
      const em = document.getElementById('regEmail').value.trim();
      if (!n) return { field: 'regFullName', msg: 'نام و نام خانوادگی الزامی است' };
      if (n.length < 2 || !regNameValid(n)) return { field: 'regFullName', msg: 'نام و نام خانوادگی معتبر نیست (فقط حروف، حداقل ۲ کاراکتر)' };
      if (!em) return { field: 'regEmail', msg: 'ایمیل الزامی است' };
      if (!regEmailValid(em)) return { field: 'regEmail', msg: 'ایمیل وارد شده معتبر نیست' };
      return null;
    }

    /* بررسی زنده ایمیل سمت سرور (فرمت/MX/disposable + تکراری) قبل از رفتن به مرحله بعد */
    async function checkEmailAvailable() {
      const em = document.getElementById('regEmail').value.trim();
      const res  = await fetch('api.php?action=check_email', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ email: em }),
      });
      return res.json(); // { ok } یا { ok:false, msg }
    }
    function validateRegStep2() {
      const p = document.getElementById('regPassword').value;
      const c = document.getElementById('regConfirm').value;
      if (!pwMeetsPolicy(p)) return { field: 'regPassword', msg: 'رمز عبور باید حداقل در سطح «متوسط» باشد: دست‌کم ۶ کاراکتر همراه با حروف بزرگ، عدد یا نماد.' };
      if (p !== c) return { field: 'regConfirm', msg: 'رمز عبور و تکرار آن یکسان نیستند' };
      return null;
    }

    function showDevCode(data) {
      const note = document.getElementById('regDevNote');
      if (data && data.dev_code) { note.hidden = false; note.textContent = 'کد تست (محیط محلی): ' + data.dev_code; }
    }

    /* مرحله ۲ → ۳: ساخت حساب در انتظار + ارسال کد */
    async function sendRegistration() {
      const btn = document.getElementById('registerSubmitBtn');
      setLoading(btn, true);
      try {
        const res = await fetch('api.php?action=register', {
          method: 'POST', headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            full_name: document.getElementById('regFullName').value.trim(),
            email:     document.getElementById('regEmail').value.trim(),
            password:  document.getElementById('regPassword').value,
            confirm_password: document.getElementById('regConfirm').value,
          }),
        });
        const data = await res.json();
        setLoading(btn, false, 'ادامه');
        if (!data.ok) {
          // خطای نام/ایمیل → بازگشت به مرحله ۱ تا قاب خطا روی همان فیلد دیده شود (نه روی مرحله رمز)
          if (data.field === 'full_name' || data.field === 'email') showRegStep(1);
          const fid = SERVER_FIELD_MAP[data.field];
          if (fid) failField(fid, data.msg || 'خطایی رخ داد');
          else showToast(data.msg || 'خطایی رخ داد', 'error');
          return false;
        }
        document.getElementById('regEmailEcho').textContent = document.getElementById('regEmail').value.trim();
        if (data.resend_cooldown) RESEND_COOLDOWN = data.resend_cooldown;
        showDevCode(data);
        startResendCooldown();
        return true;
      } catch (e) {
        setLoading(btn, false, 'ادامه');
        showToast('خطا در ارتباط با سرور', 'error');
        return false;
      }
    }

    /* مرحله ۳: تایید کد → فعال‌سازی + ورود */
    async function verifyRegCode() {
      const btn  = document.getElementById('registerSubmitBtn');
      const code = document.getElementById('regCode').value.trim();
      if (!/^\d{6}$/.test(code)) { return failField('regCode', 'کد ۶ رقمی را کامل وارد کنید'); }
      setLoading(btn, true);
      try {
        const res = await fetch('api.php?action=verify_email', {
          method: 'POST', headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ email: document.getElementById('regEmail').value.trim(), code }),
        });
        const data = await res.json();
        if (data.ok) {
          btn.classList.remove('loading');
          btn.classList.add('success');           // دکمه سبز + تیک
          setTimeout(() => window.location.replace('index.php'), 700);
          return;
        }
        setLoading(btn, false, 'تایید و ورود');
        failField('regCode', data.msg || 'کد نادرست است');
      } catch (e) {
        setLoading(btn, false, 'تایید و ورود');
        showToast('خطا در ارتباط با سرور', 'error');
      }
    }

    /* دکمه اصلی: بسته به مرحله، بعدی یا تایید */
    registerForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      const step = Number(registerForm.dataset.step || 1);
      if (step === 1) {
        const err = validateRegStep1(); if (err) { failField(err.field, err.msg); return; }
        // اعتبارسنجی سروری ایمیل (جعلی/موقت/تکراری) — اگر رد شد، همین‌جا متوقف شو
        const btn = document.getElementById('registerSubmitBtn');
        setLoading(btn, true);
        if (window.Field) Field.set('regEmail', 'loading');   // حالتِ «در حالِ بررسی…»
        try {
          const chk = await checkEmailAvailable();
          setLoading(btn, false, 'ادامه');
          if (!chk.ok) { failField('regEmail', chk.msg || 'ایمیل وارد شده معتبر نیست'); return; }
          if (window.Field) Field.set('regEmail', 'success', 'درست است');
        } catch (_) {
          setLoading(btn, false, 'ادامه');
          if (window.Field) Field.clear('regEmail');
          showToast('خطا در ارتباط با سرور', 'error'); return;
        }
        showRegStep(2);
      } else if (step === 2) {
        const err = validateRegStep2(); if (err) { failField(err.field, err.msg); return; }
        if (await sendRegistration()) showRegStep(3);
      } else {
        await verifyRegCode();
      }
    });

    document.getElementById('regBackBtn').addEventListener('click', () => {
      const step = Number(registerForm.dataset.step || 1);
      if (step > 1) showRegStep(step - 1);
    });

    // فقط رقم در فیلد کد
    document.getElementById('regCode').addEventListener('input', function () {
      this.value = this.value.replace(/\D/g, '').slice(0, 6);
    });

    /* ── ارسال مجدد: شمارش معکوس پلکانی + حالت لودینگ ── */
    const RESEND_MAX = 300; // سقف ۵ دقیقه
    /* مدت بعدی: هر بار دوبرابر (۳۰→۶۰→۱۲۰→…) تا سقف؛ با reset=true به مقدار پایه برمی‌گردد */
    function nextCooldown(btn, reset) {
      btn._cdStep = reset ? 0 : (btn._cdStep || 0) + 1;
      return Math.min(Math.round(RESEND_COOLDOWN * Math.pow(2, btn._cdStep)), RESEND_MAX);
    }
    /* شمارش معکوس عمومی (هم برای ثبت‌نام و هم فراموشی رمز) */
    function runCooldown(btn, timerEl, seconds) {
      let s = Math.round(seconds || RESEND_COOLDOWN);
      btn.disabled = true;
      if (btn._cdTimer) clearInterval(btn._cdTimer);
      const tick = () => {
        timerEl.textContent = s > 0 ? '(' + toFa(s) + ')' : '';
        if (s <= 0) { clearInterval(btn._cdTimer); btn.disabled = false; return; }
        s--;
      };
      tick();
      btn._cdTimer = setInterval(tick, 1000);
    }
    /* حالت «در حال ارسال…» روی دکمه ارسال مجدد تا کاربر تاخیر SMTP را خرابی نپندارد */
    function setResendSending(btn, on) {
      btn.classList.toggle('sending', on);
      btn.disabled = on;
      const lbl = btn.querySelector('.reg-resend-label');
      if (lbl) lbl.textContent = on ? 'در حال ارسال…' : 'ارسال مجدد کد';
    }
    const regResendBtn     = document.getElementById('regResend');
    const regResendTimerEl = document.getElementById('regResendTimer');
    function startResendCooldown() { runCooldown(regResendBtn, regResendTimerEl, nextCooldown(regResendBtn, true)); }
    regResendBtn.addEventListener('click', async () => {
      if (regResendBtn.disabled) return;
      setResendSending(regResendBtn, true);
      try {
        const res = await fetch('api.php?action=resend_code', {
          method: 'POST', headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ email: document.getElementById('regEmail').value.trim() }),
        });
        const data = await res.json();
        setResendSending(regResendBtn, false);
        if (data.ok) {
          if (data.resend_cooldown) RESEND_COOLDOWN = data.resend_cooldown;
          showDevCode(data);
          runCooldown(regResendBtn, regResendTimerEl, nextCooldown(regResendBtn, false));
          showToast('کد جدید ارسال شد', 'success');
        } else if (data.retry_after) {
          // سرور به‌خاطر محدودیت اجازه ارسال مجدد نداد → شمارش معکوس را با همان مقدار سرور نشان بده
          if (data.resend_cooldown) RESEND_COOLDOWN = data.resend_cooldown;
          runCooldown(regResendBtn, regResendTimerEl, data.retry_after);
          showToast(data.msg || 'برای ارسال مجدد کد کمی صبر کنید', 'error');
        } else showToast(data.msg || 'خطا در ارسال مجدد کد', 'error');
      } catch (e) { setResendSending(regResendBtn, false); showToast('خطا در ارتباط با سرور', 'error'); }
    });

    /* ══════════ فراموشی رمز عبور (سه‌مرحله‌ای) ══════════ */
    const forgotForm   = document.getElementById('forgotForm');
    const fpSubmitBtn  = document.getElementById('fpSubmitBtn');
    const fpBack       = document.getElementById('fpBack');
    const fpResendBtn  = document.getElementById('fpResend');
    const fpResendTime = document.getElementById('fpResendTimer');
    const FP_LABELS    = { 1: 'ارسال کد', 2: 'تایید کد', 3: 'تغییر رمز و ورود' };

    function fpShowStep(n) {
      forgotForm.dataset.step = n;
      forgotForm.querySelectorAll('.reg-step').forEach(el => { el.hidden = (Number(el.dataset.step) !== n); });
      forgotForm.querySelectorAll('.reg-seg').forEach((s, i) => s.classList.toggle('active', i < n));
      document.getElementById('fpStepNum').textContent = toFa(n);
      forgotForm.querySelector('#fpSubmitBtn .login-btn-label').textContent = FP_LABELS[n];
      // دکمه بازگشت هوشمند: مرحله ۱ → ورود، در غیر این صورت → مرحله قبل
      const backTip = (n === 1) ? 'بازگشت به ورود' : 'مرحله قبلی';
      fpBack.setAttribute('aria-label', backTip);
      fpBack.setAttribute('title', backTip);
      clearAllErrors(forgotForm);
      // لوگوی قدرت رمز فقط در مرحله رمز جدید معنا دارد
      if (n === 3) updateRegStrength(document.getElementById('fpPassword').value); else setLogoStrength(0);
      const step = forgotForm.querySelector('.reg-step[data-step="' + n + '"]');
      const first = step && step.querySelector('input');
      if (first && !forgotForm.hidden) setTimeout(() => first.focus(), 70);
    }

    function showForgot() {
      tabs.hidden = true;
      loginForm.hidden = true;
      registerForm.hidden = true;
      forgotForm.hidden = false;
      clearAllErrors(loginForm); clearAllErrors(registerForm);
      forgotForm.classList.remove('anim-in'); void forgotForm.offsetWidth; forgotForm.classList.add('anim-in');
      fpShowStep(1);
    }
    function hideForgot() {
      forgotForm.hidden = true;
      tabs.hidden = false;
      switchMode('login');           // بازگشت به تب ورود
      loginForm.hidden = false;
    }
    /* یک دکمه بازگشت برای همه‌چیز: مرحله‌به‌مرحله عقب، و از مرحله ۱ به صفحه ورود */
    function fpGoBack() {
      const step = Number(forgotForm.dataset.step || 1);
      if (step > 1) fpShowStep(step - 1);
      else hideForgot();
    }

    document.getElementById('forgotLink').addEventListener('click', showForgot);
    fpBack.addEventListener('click', fpGoBack);

    document.getElementById('fpCode').addEventListener('input', function () {
      this.value = this.value.replace(/\D/g, '').slice(0, 6);
    });

    /* مرحله ۱: ارسال کد بازیابی → مرحله ۲ */
    async function fpSendCode() {
      const em = document.getElementById('fpEmail').value.trim();
      if (!em) return failField('fpEmail', 'ایمیل الزامی است');
      if (!regEmailValid(em)) return failField('fpEmail', 'ایمیل معتبر نیست');
      setLoading(fpSubmitBtn, true);
      try {
        const res = await fetch('api.php?action=forgot_password', {
          method: 'POST', headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ email: em }),
        });
        const data = await res.json();
        setLoading(fpSubmitBtn, false, 'ارسال کد');
        if (!data.ok) { failField('fpEmail', data.msg || 'ایمیل معتبر نیست'); return; }
        document.getElementById('fpEmailEcho').textContent = em;
        if (data.resend_cooldown) RESEND_COOLDOWN = data.resend_cooldown;
        const note = document.getElementById('fpDevNote');
        if (data.dev_code) { note.hidden = false; note.textContent = 'کد تست (محیط محلی): ' + data.dev_code; }
        // اگر سرور به‌خاطر محدودیت کد تازه نفرستاد، شمارش معکوس را با مقدار واقعی سرور نشان بده
        runCooldown(fpResendBtn, fpResendTime, data.retry_after || nextCooldown(fpResendBtn, true));
        fpShowStep(2);
      } catch (e) {
        setLoading(fpSubmitBtn, false, 'ارسال کد');
        showToast('خطا در ارتباط با سرور', 'error');
      }
    }

    /* مرحله ۲: تایید کد (بدون مصرف نهایی) → مرحله ۳ */
    async function fpVerifyCode() {
      const code = document.getElementById('fpCode').value.trim();
      if (!/^\d{6}$/.test(code)) return failField('fpCode', 'کد ۶ رقمی را کامل وارد کنید');
      setLoading(fpSubmitBtn, true);
      try {
        const res = await fetch('api.php?action=verify_reset_code', {
          method: 'POST', headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ email: document.getElementById('fpEmail').value.trim(), code }),
        });
        const data = await res.json();
        setLoading(fpSubmitBtn, false, 'تایید کد');
        if (!data.ok) { failField('fpCode', data.msg || 'کد نادرست است'); return; }
        fpShowStep(3);
      } catch (e) {
        setLoading(fpSubmitBtn, false, 'تایید کد');
        showToast('خطا در ارتباط با سرور', 'error');
      }
    }

    /* مرحله ۳: تنظیم رمز جدید + ورود */
    async function fpReset() {
      const code = document.getElementById('fpCode').value.trim();
      const p    = document.getElementById('fpPassword').value;
      const c    = document.getElementById('fpConfirm').value;
      if (!pwMeetsPolicy(p)) return failField('fpPassword', 'رمز عبور باید حداقل در سطح «متوسط» باشد.');
      if (p !== c) return failField('fpConfirm', 'رمز عبور و تکرار آن یکسان نیستند');
      setLoading(fpSubmitBtn, true);
      try {
        const res = await fetch('api.php?action=reset_password', {
          method: 'POST', headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ email: document.getElementById('fpEmail').value.trim(), code, password: p, confirm_password: c }),
        });
        const data = await res.json();
        if (data.ok) {
          fpSubmitBtn.classList.remove('loading');
          fpSubmitBtn.classList.add('success');
          showToast('رمز عبور تغییر کرد', 'success');
          setTimeout(() => window.location.replace('index.php'), 700);
          return;
        }
        setLoading(fpSubmitBtn, false, 'تغییر رمز و ورود');
        if (data.field === 'password') {
          failField('fpPassword', data.msg || 'رمز عبور معتبر نیست');
        } else {
          // مشکل کد (نادرست/منقضی) → بازگشت به مرحله تایید کد
          fpShowStep(2);
          failField('fpCode', data.msg || 'کد نادرست است');
        }
      } catch (e) {
        setLoading(fpSubmitBtn, false, 'تغییر رمز و ورود');
        showToast('خطا در ارتباط با سرور', 'error');
      }
    }

    forgotForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      const step = Number(forgotForm.dataset.step || 1);
      if (step === 1) await fpSendCode();
      else if (step === 2) await fpVerifyCode();
      else await fpReset();
    });

    fpResendBtn.addEventListener('click', async () => {
      if (fpResendBtn.disabled) return;
      setResendSending(fpResendBtn, true);
      try {
        const res = await fetch('api.php?action=forgot_password', {
          method: 'POST', headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ email: document.getElementById('fpEmail').value.trim() }),
        });
        const data = await res.json();
        setResendSending(fpResendBtn, false);
        if (data.ok) {
          if (data.resend_cooldown) RESEND_COOLDOWN = data.resend_cooldown;
          const note = document.getElementById('fpDevNote');
          if (data.dev_code) { note.hidden = false; note.textContent = 'کد تست (محیط محلی): ' + data.dev_code; }
          if (data.retry_after) {
            // سرور هنوز اجازه ارسال دوباره نداد → فقط شمارش معکوس را نشان بده
            runCooldown(fpResendBtn, fpResendTime, data.retry_after);
            showToast('برای ارسال مجدد کد کمی صبر کنید', 'error');
          } else {
            runCooldown(fpResendBtn, fpResendTime, nextCooldown(fpResendBtn, false));
            showToast('کد جدید ارسال شد', 'success');
          }
        } else showToast(data.msg || 'خطا در ارسال کد', 'error');
      } catch (e) { setResendSending(fpResendBtn, false); showToast('خطا در ارتباط با سرور', 'error'); }
    });

    // مقداردهی اولیه ویزارد (وضعیت مرحله، نشانگرها، برچسب دکمه)
    showRegStep(1);
