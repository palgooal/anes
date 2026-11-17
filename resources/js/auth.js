/*
 * Progressive enhancements for the login screens. Every step is a real
 * server-rendered form, so the pages also work without JavaScript.
 */

const toArabicDigits = (value) => String(value).replace(/\d/g, (d) => '٠١٢٣٤٥٦٧٨٩'[d]);
const toLatinDigits = (value) => String(value)
    .replace(/[٠-٩]/g, (d) => d.charCodeAt(0) - 1632)
    .replace(/[۰-۹]/g, (d) => d.charCodeAt(0) - 1776);
const minutesSeconds = (seconds) => toArabicDigits(`${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`);

/**
 * Count down from data-seconds, writing m:ss into the element, then reveal
 * the elements listed in data-reveal and enable data-enable.
 */
function startCountdown(el) {
    let left = Number(el.dataset.seconds || 0);
    const finish = () => {
        document.querySelectorAll(el.dataset.hide || '').forEach((n) => { n.hidden = true; });
        document.querySelectorAll(el.dataset.reveal || '').forEach((n) => { n.hidden = false; });
        document.querySelectorAll(el.dataset.enable || '').forEach((n) => { n.disabled = false; });
        el.textContent = el.dataset.doneText || '';
    };
    if (left <= 0) {
        finish();
        return;
    }
    el.textContent = minutesSeconds(left);
    const timer = setInterval(() => {
        left -= 1;
        if (left <= 0) {
            clearInterval(timer);
            finish();
        } else {
            el.textContent = minutesSeconds(left);
        }
    }, 1000);
}

/** Four separate digit boxes: auto-advance, backspace, arrows, paste, Arabic digits, auto-submit. */
function enhanceCodeInput(group) {
    const boxes = [...group.querySelectorAll('input')];
    const form = group.closest('form');
    const value = () => boxes.map((b) => b.value).join('');

    const fill = (text, from) => {
        toLatinDigits(text).replace(/\D/g, '').split('').forEach((digit, i) => {
            if (boxes[from + i]) {
                boxes[from + i].value = digit;
            }
        });
        const next = boxes.findIndex((b) => !b.value);
        (boxes[next] || boxes[boxes.length - 1]).focus();
        if (value().length === boxes.length && form) {
            group.dataset.state = 'busy';
            form.requestSubmit();
        }
    };

    boxes.forEach((box, i) => {
        box.addEventListener('input', () => {
            const digits = toLatinDigits(box.value).replace(/\D/g, '');
            box.value = '';
            delete group.dataset.state;
            if (digits) {
                fill(digits, i);
            }
        });
        box.addEventListener('keydown', (e) => {
            if (e.key === 'Backspace' && !box.value && boxes[i - 1]) {
                boxes[i - 1].value = '';
                boxes[i - 1].focus();
                e.preventDefault();
            }
            if (e.key === 'ArrowLeft' && boxes[i + 1]) {
                boxes[i + 1].focus();
            }
            if (e.key === 'ArrowRight' && boxes[i - 1]) {
                boxes[i - 1].focus();
            }
        });
        box.addEventListener('paste', (e) => {
            e.preventDefault();
            fill((e.clipboardData || window.clipboardData).getData('text'), 0);
        });
        box.addEventListener('focus', () => box.select());
    });

    if (group.dataset.state === 'error') {
        boxes.forEach((b) => { b.value = ''; });
    }
    boxes[0].focus();
}

/** Show / hide password: <button data-reveal-password="inputId"> */
document.addEventListener('click', (e) => {
    const button = e.target.closest('[data-reveal-password]');
    if (!button) {
        return;
    }
    const input = document.getElementById(button.dataset.revealPassword);
    const show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    button.setAttribute('aria-label', show ? 'إخفاء كلمة المرور' : 'إظهار كلمة المرور');
    button.setAttribute('aria-pressed', String(show));
});

/** Normalise Arabic-Indic digits typed into phone fields before submit. */
document.querySelectorAll('input[inputmode="tel"]').forEach((input) => {
    input.form?.addEventListener('submit', () => { input.value = toLatinDigits(input.value); });
});

/** Prevent double submits and show that something is happening. */
document.querySelectorAll('form[data-busy]').forEach((form) => {
    form.addEventListener('submit', () => {
        form.querySelectorAll('button[type="submit"]').forEach((b) => { b.disabled = true; });
    });
});

/** WhatsApp links: <a data-wa="message"> using the number from <meta name="wa-number">. */
const waNumber = document.querySelector('meta[name="wa-number"]')?.content || '';
document.querySelectorAll('[data-wa]').forEach((a) => {
    a.href = `https://wa.me/${waNumber}?text=${encodeURIComponent(a.dataset.wa)}`;
});

document.querySelectorAll('[data-countdown]').forEach(startCountdown);
document.querySelectorAll('[data-code-input]').forEach(enhanceCodeInput);
