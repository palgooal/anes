/* Shared helpers for login.html and staff-login.html (demo only: nothing is sent or stored). */
(function () {
  'use strict';

  var WA_NUMBER = ''; /* رقم واتساب الخدمة بالصيغة الدولية بدون + مثل 9665XXXXXXXX */

  var $ = function (id) { return document.getElementById(id); };
  var toAr = function (n) { return String(n).replace(/\d/g, function (d) { return '٠١٢٣٤٥٦٧٨٩'[d]; }); };
  /* accept Arabic-Indic digits typed on Arabic keyboards */
  var latin = function (s) {
    return String(s).replace(/[٠-٩]/g, function (d) { return d.charCodeAt(0) - 1632; })
                    .replace(/[۰-۹]/g, function (d) { return d.charCodeAt(0) - 1776; });
  };
  var mmss = function (s) { return toAr(Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0')); };

  /* show one step of a multi-step card and focus its first control */
  function showStep(name) {
    document.querySelectorAll('[data-step]').forEach(function (el) {
      el.hidden = el.getAttribute('data-step') !== name;
    });
    var cur = document.querySelector('[data-step="' + name + '"]');
    if (cur) {
      /* prefer an explicit autofocus, then the first text field, then a button */
      var f = cur.querySelector('[autofocus]') ||
              cur.querySelector('input:not([type=radio]):not([type=checkbox]), select, textarea') ||
              cur.querySelector('.btn, button');
      if (f) setTimeout(function () { f.focus(); }, 30);
    }
  }

  function setErr(input, errEl, msg) {
    if (errEl) errEl.textContent = msg || '';
    if (input) input.setAttribute('aria-invalid', msg ? 'true' : 'false');
    return !msg;
  }

  /* countdown: calls onTick(text) every second and onDone() at the end */
  function countdown(seconds, onTick, onDone) {
    var left = seconds, t;
    onTick(mmss(left));
    t = setInterval(function () {
      left -= 1;
      if (left <= 0) { clearInterval(t); onDone(); } else onTick(mmss(left));
    }, 1000);
    return function stop() { clearInterval(t); };
  }

  /* 4-box OTP input: auto-advance, backspace, arrows, paste, Arabic digits */
  function otp(container, onComplete) {
    var boxes = [].slice.call(container.querySelectorAll('input'));
    function value() { return boxes.map(function (b) { return b.value; }).join(''); }
    function fill(str, from) {
      latin(str).replace(/\D/g, '').split('').forEach(function (d, i) {
        if (boxes[from + i]) boxes[from + i].value = d;
      });
      var next = boxes.findIndex(function (b) { return !b.value; });
      (boxes[next] || boxes[boxes.length - 1]).focus();
      if (value().length === boxes.length) onComplete(value());
    }
    boxes.forEach(function (b, i) {
      b.addEventListener('input', function () {
        var v = latin(b.value).replace(/\D/g, '');
        b.value = '';
        container.removeAttribute('data-state'); /* before fill(): completing may set a new state */
        if (v) fill(v, i);
      });
      b.addEventListener('keydown', function (e) {
        if (e.key === 'Backspace' && !b.value && boxes[i - 1]) { boxes[i - 1].value = ''; boxes[i - 1].focus(); e.preventDefault(); }
        if (e.key === 'ArrowLeft' && boxes[i + 1]) boxes[i + 1].focus();
        if (e.key === 'ArrowRight' && boxes[i - 1]) boxes[i - 1].focus();
      });
      b.addEventListener('paste', function (e) {
        e.preventDefault();
        fill((e.clipboardData || window.clipboardData).getData('text'), 0);
      });
      b.addEventListener('focus', function () { b.select(); });
    });
    return {
      value: value,
      clear: function () { boxes.forEach(function (b) { b.value = ''; }); boxes[0].focus(); },
      state: function (s) { if (s) container.setAttribute('data-state', s); else container.removeAttribute('data-state'); },
      disable: function (on) { boxes.forEach(function (b) { b.disabled = on; }); },
      focus: function () { boxes[0].focus(); }
    };
  }

  /* show/hide password toggles: <button data-reveal="inputId"> */
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-reveal]');
    if (!b) return;
    var inp = $(b.getAttribute('data-reveal'));
    var show = inp.type === 'password';
    inp.type = show ? 'text' : 'password';
    b.setAttribute('aria-label', show ? 'إخفاء كلمة المرور' : 'إظهار كلمة المرور');
    b.setAttribute('aria-pressed', show ? 'true' : 'false');
  });

  /* session-expired banner: page.html?expired=1 */
  if (/[?&]expired=1/.test(location.search) && $('expired')) $('expired').hidden = false;

  function waUrl(msg) { return 'https://wa.me/' + WA_NUMBER + '?text=' + encodeURIComponent(msg); }
  document.querySelectorAll('[data-wa]').forEach(function (a) { a.href = waUrl(a.getAttribute('data-wa')); });

  window.Auth = { $: $, toAr: toAr, latin: latin, showStep: showStep, setErr: setErr, countdown: countdown, otp: otp, waUrl: waUrl };
})();
