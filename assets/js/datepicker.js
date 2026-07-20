/* ═══════════════════════════════════════════════════════════
   datepicker.js — themed custom calendar and clock
   ───────────────────────────────────────────────────────────
   Upgrades every <input type="date"> and <input type="time"> into a custom control:
   - The native input stays hidden as the source of the value (date: yyyy-mm-dd, time: HH:MM)
     so existing code and form submission keep working unchanged.
   - A theme-matched popup with fixed positioning (flip/clamp so it never overflows the screen).
   - Calendar: three drill-down views, day/month/year. Time: hour + minute columns.
   API: window.ThemedDatePicker / window.ThemedTimePicker → enhanceAll(), refresh(input)
   ═══════════════════════════════════════════════════════════ */
(function () {
  'use strict';

  var MONTHS = ['January', 'February', 'March', 'April', 'May', 'June',
                'July', 'August', 'September', 'October', 'November', 'December'];
  var MONTHS_SHORT = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  var WD = ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'];
  var pad = function (n) { return String(n).padStart(2, '0'); };

  var CAL_SVG = '<svg class="tdp-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>';
  var CLK_SVG = '<svg class="tdp-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>';
  var NAV_PREV = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg>';
  var NAV_NEXT = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l6 6-6 6"/></svg>';
  var FOOT_DATE = '<div class="tdp-foot"><button type="button" class="tdp-today">امروز</button><button type="button" class="tdp-clear">پاک‌کردن</button></div>';
  var FOOT_TIME = '<div class="tdp-foot"><button type="button" class="tdp-now">اکنون</button><button type="button" class="tdp-clear">پاک‌کردن</button></div>';

  var openInst = null;
  function closeOpen() { if (openInst) openInst.close(); }

  /** Shared popup positioning: below the trigger, flips above if there's no room, clamped inside the modal/viewport */
  function positionPopup(trigger, pop) {
    var pad2 = 8, gap = 6;
    var tr = trigger.getBoundingClientRect();
    var pw = pop.offsetWidth, ph = pop.offsetHeight;
    var vw = window.innerWidth, vh = window.innerHeight;

    // Horizontal bounds: the viewport, and if there's a modal/card, inside it too, so the popup never overflows the modal
    var minX = pad2, maxX = vw - pad2;
    var host = trigger.closest('.modal, .login-card');
    if (host) {
      var hr = host.getBoundingClientRect();
      minX = Math.max(minX, hr.left + 12);
      maxX = Math.min(maxX, hr.right - 12);
    }

    // Vertical: below, otherwise above, otherwise pinned to the bottom
    var top;
    if (tr.bottom + gap + ph <= vh - pad2)      top = tr.bottom + gap;
    else if (tr.top - gap - ph >= pad2)         top = tr.top - gap - ph;
    else                                        top = vh - ph - pad2;

    // Horizontal (RTL): prefer aligning the popup's right edge with the field's right edge so it
    // opens inward/toward the left and doesn't overflow the screen's right edge. If that would push
    // the popup past the modal/screen's left boundary, align to the field's left edge instead
    // (the time field's case on the modal's left side).
    var left = tr.right - pw;
    if (left < minX) left = tr.left;

    // final hard clamp
    if (left + pw > maxX) left = maxX - pw;
    if (left < minX)      left = minX;
    if (top + ph > vh - pad2) top = vh - ph - pad2;
    if (top < pad2)           top = pad2;

    pop.style.position = 'fixed';
    pop.style.insetInlineStart = 'auto';
    pop.style.right = 'auto';
    pop.style.left = Math.round(left) + 'px';
    pop.style.top = Math.round(top) + 'px';
  }
  /** Position + one repeat on the next frame (lets the modal's layout/scroll settle after opening) */
  function placeNow(trigger, pop) {
    positionPopup(trigger, pop);
    requestAnimationFrame(function () { if (pop.classList.contains('open')) positionPopup(trigger, pop); });
  }

  /** Builds the shell (wrap + trigger hiding the native input + pop). Returns {wrap, trigger, valueSpan, pop} */
  function scaffold(input, iconSvg, popClass) {
    var wrap = document.createElement('div');
    wrap.className = 'tdp';
    if (input.style.flex)  wrap.style.flex  = input.style.flex;
    if (input.style.width) wrap.style.width = input.style.width;
    input.parentNode.insertBefore(wrap, input);
    wrap.appendChild(input);
    input.classList.add('tdp-native');
    input.tabIndex = -1;
    input.setAttribute('aria-hidden', 'true');

    var trigger = document.createElement('button');
    trigger.type = 'button';
    trigger.className = 'tdp-trigger';
    trigger.setAttribute('aria-haspopup', 'dialog');
    if (input.getAttribute('aria-label')) trigger.setAttribute('aria-label', input.getAttribute('aria-label'));
    // Icon comes first so it lands on the right (start of the field) in RTL layout
    trigger.innerHTML = iconSvg + '<span class="tdp-value"></span>';

    var pop = document.createElement('div');
    pop.className = popClass;
    pop.setAttribute('dir', 'ltr');

    wrap.appendChild(trigger);
    // Popup is portaled to body so ancestors with a transform (like a modal) don't break
    // position:fixed and cause the popup to be clipped/misplaced relative to the modal.
    document.body.appendChild(pop);
    return { wrap: wrap, trigger: trigger, valueSpan: trigger.querySelector('.tdp-value'), pop: pop };
  }

  function fire(input) {
    input.dispatchEvent(new Event('change', { bubbles: true }));
    input.dispatchEvent(new Event('input', { bubbles: true }));
  }

  // ───────────────────────── DATE PICKER ─────────────────────────
  function parseDate(val) {
    var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(val || '');
    return m ? { y: +m[1], m: +m[2] - 1, d: +m[3] } : null;
  }
  var fmtDateVal = function (y, m, d) { return y + '-' + pad(m + 1) + '-' + pad(d); };
  var fmtDateDsp = function (y, m, d) { return y + '/' + pad(m + 1) + '/' + pad(d); };

  function headerDate(titleText, action) {
    var title = action
      ? '<button type="button" class="tdp-title" data-go="' + action + '">' + titleText + '</button>'
      : '<span class="tdp-title tdp-title-static">' + titleText + '</span>';
    return '<div class="tdp-head"><button type="button" class="tdp-nav" data-nav="-1" aria-label="قبلی">' + NAV_PREV + '</button>'
      + title + '<button type="button" class="tdp-nav" data-nav="1" aria-label="بعدی">' + NAV_NEXT + '</button></div>';
  }

  function enhanceDate(input) {
    if (input.dataset.tdp) return;
    input.dataset.tdp = '1';
    var s = scaffold(input, CAL_SVG, 'tdp-pop');
    var placeholder = input.getAttribute('placeholder') || 'انتخاب تاریخ';
    var view = null, mode = 'days';

    function syncLabel() {
      var v = parseDate(input.value);
      if (v) { s.valueSpan.textContent = fmtDateDsp(v.y, v.m, v.d); s.valueSpan.classList.remove('is-empty'); }
      else   { s.valueSpan.textContent = placeholder;             s.valueSpan.classList.add('is-empty'); }
    }
    input._tdpRefresh = syncLabel;
    input._tdpFocus = function () { s.trigger.focus(); };

    function renderDays() {
      var sel = parseDate(input.value), t = new Date();
      var ty = t.getFullYear(), tm = t.getMonth(), td = t.getDate();
      var startWd = new Date(view.y, view.m, 1).getDay();
      var days = new Date(view.y, view.m + 1, 0).getDate();
      var h = headerDate(MONTHS[view.m] + ' ' + view.y, 'months') + '<div class="tdp-grid">';
      for (var w = 0; w < 7; w++) h += '<span class="tdp-wd">' + WD[w] + '</span>';
      for (var e = 0; e < startWd; e++) h += '<span class="tdp-day tdp-empty"></span>';
      for (var d = 1; d <= days; d++) {
        var isT = (view.y === ty && view.m === tm && d === td);
        var isS = sel && sel.y === view.y && sel.m === view.m && sel.d === d;
        h += '<button type="button" class="tdp-day' + (isT ? ' is-today' : '') + (isS ? ' is-sel' : '') + '" data-d="' + d + '">' + d + '</button>';
      }
      s.pop.innerHTML = h + '</div>' + FOOT_DATE;
    }
    function renderMonths() {
      var sel = parseDate(input.value), t = new Date(), ty = t.getFullYear(), tm = t.getMonth();
      var h = headerDate(String(view.y), 'years') + '<div class="tdp-grid tdp-grid-my">';
      for (var i = 0; i < 12; i++) {
        var isT = (view.y === ty && i === tm), isS = sel && sel.y === view.y && sel.m === i;
        h += '<button type="button" class="tdp-cell' + (isT ? ' is-today' : '') + (isS ? ' is-sel' : '') + '" data-month="' + i + '">' + MONTHS_SHORT[i] + '</button>';
      }
      s.pop.innerHTML = h + '</div>' + FOOT_DATE;
    }
    function renderYears() {
      var sel = parseDate(input.value), ty = new Date().getFullYear();
      var start = view.y - (((view.y % 12) + 12) % 12);
      var h = headerDate(start + ' – ' + (start + 11), null) + '<div class="tdp-grid tdp-grid-my">';
      for (var i = 0; i < 12; i++) {
        var y = start + i, isT = (y === ty), isS = sel && sel.y === y;
        h += '<button type="button" class="tdp-cell' + (isT ? ' is-today' : '') + (isS ? ' is-sel' : '') + '" data-year="' + y + '">' + y + '</button>';
      }
      s.pop.innerHTML = h + '</div>' + FOOT_DATE;
    }
    function render() {
      if (mode === 'months') renderMonths();
      else if (mode === 'years') renderYears();
      else renderDays();
      if (s.wrap.classList.contains('open')) positionPopup(s.trigger, s.pop);
    }
    function nav(dir) {
      if (mode === 'days') { view.m += dir; if (view.m < 0) { view.m = 11; view.y--; } if (view.m > 11) { view.m = 0; view.y++; } }
      else if (mode === 'months') view.y += dir;
      else view.y += dir * 12;
      render();
    }
    function commit(y, m, d) { input.value = fmtDateVal(y, m, d); syncLabel(); fire(input); }

    s.pop.addEventListener('click', function (ev) {
      ev.stopPropagation();
      var nb = ev.target.closest('.tdp-nav'); if (nb) { nav(+nb.dataset.nav); return; }
      var ti = ev.target.closest('.tdp-title[data-go]'); if (ti) { mode = ti.dataset.go; render(); return; }
      var mo = ev.target.closest('[data-month]'); if (mo) { view.m = +mo.dataset.month; mode = 'days'; render(); return; }
      var yr = ev.target.closest('[data-year]'); if (yr) { view.y = +yr.dataset.year; mode = 'months'; render(); return; }
      var da = ev.target.closest('.tdp-day:not(.tdp-empty)'); if (da) { commit(view.y, view.m, +da.dataset.d); close(); return; }
      if (ev.target.closest('.tdp-today')) { var t = new Date(); commit(t.getFullYear(), t.getMonth(), t.getDate()); close(); return; }
      if (ev.target.closest('.tdp-clear')) { input.value = ''; syncLabel(); fire(input); close(); return; }
    });

    function open() {
      var sel = parseDate(input.value), now = new Date();
      view = sel ? { y: sel.y, m: sel.m } : { y: now.getFullYear(), m: now.getMonth() };
      mode = 'days'; closeOpen(); render();
      s.wrap.classList.add('open'); s.pop.classList.add('open');
      placeNow(s.trigger, s.pop); openInst = inst;
    }
    function close() { s.wrap.classList.remove('open'); s.pop.classList.remove('open'); if (openInst === inst) openInst = null; }
    var inst = { close: close, reposition: function () { if (s.wrap.classList.contains('open')) positionPopup(s.trigger, s.pop); } };

    s.trigger.addEventListener('click', function (ev) { ev.stopPropagation(); if (s.wrap.classList.contains('open')) close(); else open(); });
    window.addEventListener('scroll', inst.reposition, true);
    window.addEventListener('resize', inst.reposition);
    syncLabel();
  }

  // ───────────────────────── TIME PICKER ─────────────────────────
  function parseTime(val) {
    var m = /^(\d{1,2}):(\d{2})/.exec(val || '');
    if (!m) return null;
    var h = +m[1], mi = +m[2];
    return (h > 23 || mi > 59) ? null : { h: h, m: mi };
  }
  var fmtTime = function (h, m) { return pad(h) + ':' + pad(m); };

  function enhanceTime(input) {
    if (input.dataset.tdp) return;
    input.dataset.tdp = '1';
    var s = scaffold(input, CLK_SVG, 'ttp-pop');
    var placeholder = input.getAttribute('placeholder') || 'انتخاب ساعت';

    function syncLabel() {
      var v = parseTime(input.value);
      if (v) { s.valueSpan.textContent = fmtTime(v.h, v.m); s.valueSpan.classList.remove('is-empty'); }
      else   { s.valueSpan.textContent = placeholder;       s.valueSpan.classList.add('is-empty'); }
    }
    input._tdpRefresh = syncLabel;
    input._tdpFocus = function () { s.trigger.focus(); };

    function render() {
      var v = parseTime(input.value), sh = v ? v.h : -1, sm = v ? v.m : -1;
      var h = '<div class="ttp-cols"><div class="ttp-col" data-unit="h">';
      for (var i = 0; i < 24; i++) h += '<button type="button" class="ttp-opt' + (i === sh ? ' is-sel' : '') + '" data-h="' + i + '">' + pad(i) + '</button>';
      h += '</div><div class="ttp-col" data-unit="m">';
      for (var j = 0; j < 60; j++) h += '<button type="button" class="ttp-opt' + (j === sm ? ' is-sel' : '') + '" data-m="' + j + '">' + pad(j) + '</button>';
      s.pop.innerHTML = h + '</div></div>' + FOOT_TIME;
    }
    function scrollToSel() {
      s.pop.querySelectorAll('.ttp-opt.is-sel').forEach(function (el) {
        var col = el.parentNode;
        col.scrollTop = el.offsetTop - col.offsetTop - 70;
      });
    }
    function markSel() {
      var v = parseTime(input.value); if (!v) return;
      s.pop.querySelectorAll('[data-h]').forEach(function (el) { el.classList.toggle('is-sel', +el.dataset.h === v.h); });
      s.pop.querySelectorAll('[data-m]').forEach(function (el) { el.classList.toggle('is-sel', +el.dataset.m === v.m); });
    }
    function commit(h, m) { input.value = fmtTime(h, m); syncLabel(); fire(input); }

    s.pop.addEventListener('click', function (ev) {
      ev.stopPropagation();
      var cur = parseTime(input.value) || { h: 0, m: 0 };
      var hb = ev.target.closest('[data-h]'); if (hb) { commit(+hb.dataset.h, cur.m); markSel(); return; }
      var mb = ev.target.closest('[data-m]'); if (mb) { commit(cur.h, +mb.dataset.m); markSel(); return; }
      if (ev.target.closest('.tdp-now')) { var n = new Date(); commit(n.getHours(), n.getMinutes()); markSel(); scrollToSel(); return; }
      if (ev.target.closest('.tdp-clear')) { input.value = ''; syncLabel(); fire(input); close(); return; }
    });

    function open() { closeOpen(); render(); s.wrap.classList.add('open'); s.pop.classList.add('open'); placeNow(s.trigger, s.pop); scrollToSel(); openInst = inst; }
    function close() { s.wrap.classList.remove('open'); s.pop.classList.remove('open'); if (openInst === inst) openInst = null; }
    var inst = { close: close, reposition: function () { if (s.wrap.classList.contains('open')) positionPopup(s.trigger, s.pop); } };

    s.trigger.addEventListener('click', function (ev) { ev.stopPropagation(); if (s.wrap.classList.contains('open')) close(); else open(); });
    window.addEventListener('scroll', inst.reposition, true);
    window.addEventListener('resize', inst.reposition);
    syncLabel();
  }

  // ───────────────────────── UNIFIED DATE + TIME PICKER ─────────────────────────
  // A single control (single hidden input, single trigger, single popup) combining the
  // calendar with an inline hour/minute picker, for fields where date and time form one
  // value (e.g. an expiry moment) and shouldn't be two separate controls.
  // Value format: "YYYY-MM-DDTHH:MM" (same shape as <input type="datetime-local">).
  function parseDateTime(val) {
    var m = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/.exec(val || '');
    if (!m) return null;
    var h = +m[4], mi = +m[5];
    if (h > 23 || mi > 59) return null;
    return { y: +m[1], m: +m[2] - 1, d: +m[3], h: h, mi: mi };
  }
  var fmtDateTimeVal = function (y, m, d, h, mi) { return fmtDateVal(y, m, d) + 'T' + fmtTime(h, mi); };
  var fmtDateTimeDsp = function (y, m, d, h, mi) { return fmtDateDsp(y, m, d) + '  ' + fmtTime(h, mi); };
  var FOOT_DT = '<div class="tdp-foot tdp-foot-3"><button type="button" class="tdp-ok">تایید</button><button type="button" class="tdp-now">اکنون</button><button type="button" class="tdp-clear">پاک‌کردن</button></div>';

  function enhanceDateTime(input) {
    if (input.dataset.tdp) return;
    input.dataset.tdp = '1';
    var s = scaffold(input, CAL_SVG, 'tdp-pop tdp-dt-pop');
    var placeholder = input.getAttribute('placeholder') || 'انتخاب تاریخ و ساعت';
    var view = null, mode = 'days';

    function syncLabel() {
      var v = parseDateTime(input.value);
      if (v) { s.valueSpan.textContent = fmtDateTimeDsp(v.y, v.m, v.d, v.h, v.mi); s.valueSpan.classList.remove('is-empty'); }
      else   { s.valueSpan.textContent = placeholder;                            s.valueSpan.classList.add('is-empty'); }
    }
    input._tdpRefresh = syncLabel;
    input._tdpFocus = function () { s.trigger.focus(); };

    // current value, falling back to "now" so picking only a date (or only a time) has a sane default for the other half
    function currentDT() {
      var v = parseDateTime(input.value), t = new Date();
      return v || { y: t.getFullYear(), m: t.getMonth(), d: t.getDate(), h: 0, mi: 0 };
    }
    function setValue(y, m, d, h, mi) { input.value = fmtDateTimeVal(y, m, d, h, mi); syncLabel(); fire(input); }

    function timeRowHtml() {
      var v = parseDateTime(input.value), sh = v ? v.h : -1, sm = v ? v.mi : -1;
      var h = '<div class="tdp-dt-divider"></div><div class="tdp-dt-time"><div class="tdp-dt-time-label">' + CLK_SVG + '<span>ساعت</span></div><div class="ttp-cols tdp-dt-cols">';
      h += '<div class="ttp-col" data-unit="h">';
      for (var i = 0; i < 24; i++) h += '<button type="button" class="ttp-opt' + (i === sh ? ' is-sel' : '') + '" data-h="' + i + '">' + pad(i) + '</button>';
      h += '</div><div class="ttp-col" data-unit="m">';
      for (var j = 0; j < 60; j++) h += '<button type="button" class="ttp-opt' + (j === sm ? ' is-sel' : '') + '" data-m="' + j + '">' + pad(j) + '</button>';
      h += '</div></div></div>';
      return h;
    }
    function scrollTimeToSel() {
      s.pop.querySelectorAll('.tdp-dt-cols .ttp-opt.is-sel').forEach(function (el) {
        var col = el.parentNode;
        col.scrollTop = el.offsetTop - col.offsetTop - 50;
      });
    }
    function markTimeSel() {
      var v = parseDateTime(input.value);
      s.pop.querySelectorAll('.tdp-dt-cols [data-h]').forEach(function (el) { el.classList.toggle('is-sel', !!v && +el.dataset.h === v.h); });
      s.pop.querySelectorAll('.tdp-dt-cols [data-m]').forEach(function (el) { el.classList.toggle('is-sel', !!v && +el.dataset.m === v.mi); });
    }

    function renderDays() {
      var v = parseDateTime(input.value), t = new Date();
      var ty = t.getFullYear(), tm = t.getMonth(), td = t.getDate();
      var startWd = new Date(view.y, view.m, 1).getDay();
      var days = new Date(view.y, view.m + 1, 0).getDate();
      var h = headerDate(MONTHS[view.m] + ' ' + view.y, 'months') + '<div class="tdp-grid">';
      for (var w = 0; w < 7; w++) h += '<span class="tdp-wd">' + WD[w] + '</span>';
      for (var e = 0; e < startWd; e++) h += '<span class="tdp-day tdp-empty"></span>';
      for (var d = 1; d <= days; d++) {
        var isT = (view.y === ty && view.m === tm && d === td);
        var isS = v && v.y === view.y && v.m === view.m && v.d === d;
        h += '<button type="button" class="tdp-day' + (isT ? ' is-today' : '') + (isS ? ' is-sel' : '') + '" data-d="' + d + '">' + d + '</button>';
      }
      s.pop.innerHTML = h + '</div>' + timeRowHtml() + FOOT_DT;
      scrollTimeToSel();
    }
    function renderMonths() {
      var v = parseDateTime(input.value), t = new Date(), ty = t.getFullYear(), tm = t.getMonth();
      var h = headerDate(String(view.y), 'years') + '<div class="tdp-grid tdp-grid-my">';
      for (var i = 0; i < 12; i++) {
        var isT = (view.y === ty && i === tm), isS = v && v.y === view.y && v.m === i;
        h += '<button type="button" class="tdp-cell' + (isT ? ' is-today' : '') + (isS ? ' is-sel' : '') + '" data-month="' + i + '">' + MONTHS_SHORT[i] + '</button>';
      }
      s.pop.innerHTML = h + '</div>' + FOOT_DT;
    }
    function renderYears() {
      var v = parseDateTime(input.value), ty = new Date().getFullYear();
      var start = view.y - (((view.y % 12) + 12) % 12);
      var h = headerDate(start + ' – ' + (start + 11), null) + '<div class="tdp-grid tdp-grid-my">';
      for (var i = 0; i < 12; i++) {
        var y = start + i, isT = (y === ty), isS = v && v.y === y;
        h += '<button type="button" class="tdp-cell' + (isT ? ' is-today' : '') + (isS ? ' is-sel' : '') + '" data-year="' + y + '">' + y + '</button>';
      }
      s.pop.innerHTML = h + '</div>' + FOOT_DT;
    }
    function render() {
      if (mode === 'months') renderMonths();
      else if (mode === 'years') renderYears();
      else renderDays();
      if (s.wrap.classList.contains('open')) positionPopup(s.trigger, s.pop);
    }
    function nav(dir) {
      if (mode === 'days') { view.m += dir; if (view.m < 0) { view.m = 11; view.y--; } if (view.m > 11) { view.m = 0; view.y++; } }
      else if (mode === 'months') view.y += dir;
      else view.y += dir * 12;
      render();
    }
    function commitDate(y, m, d) { var cur = currentDT(); setValue(y, m, d, cur.h, cur.mi); }
    function commitTime(h, mi)   { var cur = currentDT(); setValue(cur.y, cur.m, cur.d, h, mi); }

    s.pop.addEventListener('click', function (ev) {
      ev.stopPropagation();
      var nb = ev.target.closest('.tdp-nav'); if (nb) { nav(+nb.dataset.nav); return; }
      var ti = ev.target.closest('.tdp-title[data-go]'); if (ti) { mode = ti.dataset.go; render(); return; }
      var mo = ev.target.closest('[data-month]'); if (mo) { view.m = +mo.dataset.month; mode = 'days'; render(); return; }
      var yr = ev.target.closest('[data-year]'); if (yr) { view.y = +yr.dataset.year; mode = 'months'; render(); return; }
      var da = ev.target.closest('.tdp-day:not(.tdp-empty)'); if (da) { commitDate(view.y, view.m, +da.dataset.d); render(); return; }
      var hb = ev.target.closest('.tdp-dt-cols [data-h]'); if (hb) { commitTime(+hb.dataset.h, currentDT().mi); markTimeSel(); return; }
      var mb = ev.target.closest('.tdp-dt-cols [data-m]'); if (mb) { commitTime(currentDT().h, +mb.dataset.m); markTimeSel(); return; }
      if (ev.target.closest('.tdp-now')) {
        var n = new Date();
        setValue(n.getFullYear(), n.getMonth(), n.getDate(), n.getHours(), n.getMinutes());
        mode = 'days'; view = { y: n.getFullYear(), m: n.getMonth() }; render(); close(); return;
      }
      if (ev.target.closest('.tdp-clear')) { input.value = ''; syncLabel(); fire(input); close(); return; }
      if (ev.target.closest('.tdp-ok')) { close(); return; }
    });

    function open() {
      var v = parseDateTime(input.value), now = new Date();
      view = v ? { y: v.y, m: v.m } : { y: now.getFullYear(), m: now.getMonth() };
      mode = 'days'; closeOpen(); render();
      s.wrap.classList.add('open'); s.pop.classList.add('open');
      placeNow(s.trigger, s.pop); openInst = inst;
    }
    function close() { s.wrap.classList.remove('open'); s.pop.classList.remove('open'); if (openInst === inst) openInst = null; }
    var inst = { close: close, reposition: function () { if (s.wrap.classList.contains('open')) positionPopup(s.trigger, s.pop); } };

    s.trigger.addEventListener('click', function (ev) { ev.stopPropagation(); if (s.wrap.classList.contains('open')) close(); else open(); });
    window.addEventListener('scroll', inst.reposition, true);
    window.addEventListener('resize', inst.reposition);
    syncLabel();
  }

  // ───────────────────────── PUBLIC API ─────────────────────────
  function enhanceAllDates(root) { (root || document).querySelectorAll('input[type="date"]:not([data-tdp])').forEach(enhanceDate); }
  function enhanceAllTimes(root) { (root || document).querySelectorAll('input[type="time"]:not([data-tdp])').forEach(enhanceTime); }
  function enhanceAllDateTimes(root) { (root || document).querySelectorAll('input[data-tdp-kind="datetime"]:not([data-tdp])').forEach(enhanceDateTime); }
  function refresh(input) { if (input && input._tdpRefresh) input._tdpRefresh(); }

  document.addEventListener('click', closeOpen);
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeOpen(); });

  window.ThemedDatePicker = { enhanceAll: enhanceAllDates, enhance: enhanceDate, refresh: refresh };
  window.ThemedTimePicker = { enhanceAll: enhanceAllTimes, enhance: enhanceTime, refresh: refresh };
  window.ThemedDateTimePicker = { enhanceAll: enhanceAllDateTimes, enhance: enhanceDateTime, refresh: refresh };

  function initAll() { enhanceAllDates(); enhanceAllTimes(); enhanceAllDateTimes(); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initAll);
  else initAll();
})();
