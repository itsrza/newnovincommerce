/**
 * Novin Commerce — dashboard animations (catalog snapshot).
 * Pure vanilla JS, no dependencies. Animates:
 *  - [data-nv-count]  number count-up with Persian digits
 *  - [data-nv-bar]    horizontal bar grow (width %)
 *  - [data-nv-ring]   donut stroke draw (percent)
 *  - [data-nv-h]      vertical bar grow (height px)
 */
(function () {
    'use strict';

    function ready(fn) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', fn);
        } else {
            fn();
        }
    }

    function faDigits(str) {
        var fa = '۰۱۲۳۴۵۶۷۸۹';
        return String(str).replace(/[0-9]/g, function (d) {
            return fa[parseInt(d, 10)];
        });
    }

    function fmt(n) {
        var s = Number(n || 0).toLocaleString('en-US');
        return faDigits(s);
    }

    function countUp(el) {
        var target = parseInt(el.getAttribute('data-nv-count'), 10) || 0;
        var dur = 950;
        var t0 = null;
        function frame(ts) {
            if (!t0) { t0 = ts; }
            var p = Math.min(1, (ts - t0) / dur);
            var eased = 1 - Math.pow(1 - p, 3);
            var v = Math.round(target * eased);
            el.textContent = fmt(v);
            if (p < 1) {
                window.requestAnimationFrame(frame);
            }
        }
        window.requestAnimationFrame(frame);
    }

    ready(function () {
        var counters = document.querySelectorAll('[data-nv-count]');
        Array.prototype.forEach.call(counters, function (el, i) {
            setTimeout(function () { countUp(el); }, 60 + i * 70);
        });

        var bars = document.querySelectorAll('[data-nv-bar]');
        Array.prototype.forEach.call(bars, function (el, i) {
            var w = parseFloat(el.getAttribute('data-nv-bar')) || 0;
            setTimeout(function () { el.style.width = w + '%'; }, 200 + i * 90);
        });

        var cols = document.querySelectorAll('[data-nv-h]');
        Array.prototype.forEach.call(cols, function (el, i) {
            var h = parseFloat(el.getAttribute('data-nv-h')) || 0;
            setTimeout(function () { el.style.height = h + 'px'; }, 200 + i * 60);
        });

        var ring = document.querySelector('[data-nv-ring]');
        if (ring) {
            var pct = Math.min(100, Math.max(0, parseFloat(ring.getAttribute('data-nv-ring')) || 0));
            var C = 326.7;
            setTimeout(function () {
                ring.style.strokeDashoffset = String(C - (C * pct / 100));
            }, 250);
        }
    });
})();
