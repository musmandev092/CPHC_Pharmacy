/* CPHC Pharmacy POS — vanilla JS.  No framework.  No dependencies.
   Responsibilities:
     1. Barcode scanner focus — keep keystrokes flowing into the search field.
     2. Debounce live-search inputs.
     3. Manager-PIN modal helper for sensitive operations.
     4. Topbar dropdown menus (open/close on click, close on outside-click / escape).
*/

(() => {
    'use strict';

    // ── 1) Barcode scanner focus ────────────────────────────────────────
    const target = document.querySelector('[data-barcode-target]');
    if (target) {
        const refocus = () => {
            if (document.activeElement && document.activeElement.tagName === 'INPUT') return;
            if (document.activeElement && document.activeElement.tagName === 'TEXTAREA') return;
            target.focus();
        };
        document.addEventListener('click', refocus);
        window.addEventListener('focus', refocus);
        refocus();
    }

    // ── 2) Debounce + live search ───────────────────────────────────────
    function debounce(fn, ms) {
        let t = 0;
        return function (...args) {
            clearTimeout(t);
            t = setTimeout(() => fn.apply(this, args), ms);
        };
    }
    document.querySelectorAll('[data-live-search]').forEach((el) => {
        const url = el.dataset.liveSearch;
        const out = document.querySelector(el.dataset.target || '#live-search-results');
        if (!out) return;
        const run = debounce(async () => {
            const q = el.value.trim();
            if (q.length < 2) { out.innerHTML = ''; return; }
            try {
                const res = await fetch(url + '?q=' + encodeURIComponent(q), {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                });
                if (!res.ok) { out.innerHTML = ''; return; }
                out.innerHTML = await res.text();
            } catch (_) { /* swallow; next keystroke retries */ }
        }, 150);
        el.addEventListener('input', run);
    });

    // ── 3) Manager-PIN modal ────────────────────────────────────────────
    document.querySelectorAll('[data-manager-pin]').forEach((btn) => {
        btn.addEventListener('click', (ev) => {
            ev.preventDefault();
            const action = btn.dataset.managerPin;
            const form = btn.closest('form');
            if (!form) return;
            const pin = window.prompt('Manager PIN required for: ' + action);
            if (!pin) return;
            const hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = 'manager_pin';
            hidden.value = pin;
            form.appendChild(hidden);
            form.submit();
        });
    });

    // ── 4) data-print-page → window.print() (CSP-safe replacement for inline onclick)
    document.querySelectorAll('[data-print-page]').forEach((btn) => {
        btn.addEventListener('click', () => window.print());
    });

    // ── 5) Topbar dropdown menus ────────────────────────────────────────
    const groups = document.querySelectorAll('[data-nav-group]');
    const closeAll = () => {
        groups.forEach((g) => {
            g.dataset.open = 'false';
            const m = g.querySelector('.nav__menu');
            if (m) m.hidden = true;
        });
    };
    groups.forEach((g) => {
        const toggle = g.querySelector('[data-nav-toggle]');
        const menu = g.querySelector('.nav__menu');
        if (!toggle || !menu) return;
        toggle.addEventListener('click', (ev) => {
            ev.stopPropagation();
            const isOpen = g.dataset.open === 'true';
            closeAll();
            if (!isOpen) {
                g.dataset.open = 'true';
                menu.hidden = false;
            }
        });
        menu.addEventListener('click', (ev) => ev.stopPropagation());
    });
    document.addEventListener('click', closeAll);
    document.addEventListener('keydown', (ev) => {
        if (ev.key === 'Escape') closeAll();
    });
})();
