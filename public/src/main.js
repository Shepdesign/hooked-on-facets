// Public runtime entry. Wires delegated event listeners on the document so
// they survive page swaps (no re-attaching on every refresh).
//
// Flow:
//   change → store.set() → subscriber → pushState + refresh()
//   refresh() → fetch(currentUrl) → swap .hof-results + each [data-hof-facet]
//   popstate → store.hydrateFromUrl() → refresh()

import './styles/facets.css';
import { Store, buildUrl } from './state.js';
import { refresh } from './refresh.js';
import { initSwiper } from './swiper.js';
import { initAsk } from './ask.js';
import { initVisualDna } from './visual-dna.js';

const store = new Store();
store.hydrateFromUrl();

initSwiper();
initAsk(store);
initVisualDna(store);

const DEBOUNCE_MS = 250;

// ── State → URL → refresh ──────────────────────────────────────────────────

let pending = null;
store.subscribe((state) => {
    clearTimeout(pending);
    pending = setTimeout(() => {
        const url = buildUrl(state);
        if (url.toString() !== window.location.href) {
            history.pushState(null, '', url.toString());
        }
        refresh(url.toString());
    }, 0);
});

window.addEventListener('popstate', () => {
    store.hydrateFromUrl();
    refresh();
});

// Loading affordance — dim .hof-results during a refresh.
document.addEventListener('hof:refresh', (e) => {
    const phase = e.detail?.phase;
    document.querySelectorAll('.hof-results').forEach((el) => {
        if (phase === 'start') el.setAttribute('data-hof-loading', '');
        else el.removeAttribute('data-hof-loading');
    });
});

// ── DOM events → state ────────────────────────────────────────────────────

document.addEventListener('change', (e) => {
    const facetEl = e.target.closest('[data-hof-facet]');
    if (!facetEl) return;

    const name = facetEl.getAttribute('data-hof-facet');
    const display = facetEl.getAttribute('data-hof-display');
    if (!name) return;

    if (display === 'checkbox' || display === 'swatch' || display === 'swiper' || display === 'hierarchy') {
        const values = Array.from(
            facetEl.querySelectorAll('input[type="checkbox"]:checked')
        ).map((cb) => cb.value);

        if (display === 'swatch') {
            // Optimistic selected-state flip so the tile updates before the
            // server round-trip completes. refresh() will reconcile.
            facetEl.querySelectorAll('[data-hof-swatch-value]').forEach((tile) => {
                const cb = tile.querySelector('input[type="checkbox"]');
                if (cb?.checked) tile.setAttribute('data-hof-selected', '1');
                else tile.removeAttribute('data-hof-selected');
            });
        }

        store.set(name, values);
        return;
    }

    if (display === 'radio') {
        const v = facetEl.querySelector('input[type="radio"]:checked')?.value ?? '';
        store.set(name, v === '' ? [] : [v]);
        return;
    }

    if (display === 'dropdown') {
        const v = facetEl.querySelector('[data-hof-select]')?.value ?? '';
        store.set(name, v === '' ? [] : [v]);
        return;
    }

    if (display === 'toggle') {
        const cb = facetEl.querySelector('[data-hof-toggle]');
        const trueValue = facetEl.getAttribute('data-hof-true-value') || '1';
        store.set(name, cb?.checked ? [trueValue] : []);
        return;
    }

    if (display === 'range') {
        const min = facetEl.querySelector('[data-hof-input="min"]')?.value ?? '';
        const max = facetEl.querySelector('[data-hof-input="max"]')?.value ?? '';
        store.set(name, { min, max });
        return;
    }

    if (display === 'date_range') {
        const minIso = facetEl.querySelector('[data-hof-input="min"]')?.value ?? '';
        const maxIso = facetEl.querySelector('[data-hof-input="max"]')?.value ?? '';
        store.set(name, {
            min: isoToEpoch(minIso, false),
            max: isoToEpoch(maxIso, true),
        });
        return;
    }

    if (display === 'search') {
        // change fires on blur — input handler below handles live typing.
        store.set(name, e.target.value);
    }
});

// ISO yyyy-mm-dd → Unix epoch seconds (UTC).
// `endOfDay`: if true, snap to 23:59:59 so the day is fully included as a max bound.
function isoToEpoch(iso, endOfDay) {
    if (!iso) return '';
    const ts = Date.parse(iso + 'T' + (endOfDay ? '23:59:59' : '00:00:00') + 'Z');
    return Number.isFinite(ts) ? Math.floor(ts / 1000) : '';
}

// Live search + live range updates while typing. Debounced so we don't
// flood the network with one request per keystroke.
const debouncedSet = debounce((name, value) => store.set(name, value), DEBOUNCE_MS);

document.addEventListener('input', (e) => {
    const facetEl = e.target.closest('[data-hof-facet]');
    if (!facetEl) return;
    const name = facetEl.getAttribute('data-hof-facet');
    const display = facetEl.getAttribute('data-hof-display');
    if (!name) return;

    if (display === 'search') {
        debouncedSet(name, e.target.value);
        return;
    }

    if (display === 'range' && e.target.matches('[data-hof-input]')) {
        const min = facetEl.querySelector('[data-hof-input="min"]')?.value ?? '';
        const max = facetEl.querySelector('[data-hof-input="max"]')?.value ?? '';
        debouncedSet(name, { min, max });
    }

    if (display === 'date_range' && e.target.matches('[data-hof-input]')) {
        const minIso = facetEl.querySelector('[data-hof-input="min"]')?.value ?? '';
        const maxIso = facetEl.querySelector('[data-hof-input="max"]')?.value ?? '';
        debouncedSet(name, {
            min: isoToEpoch(minIso, false),
            max: isoToEpoch(maxIso, true),
        });
    }
});

// Reset link hijack — clear all and refresh in place.
document.addEventListener('click', (e) => {
    const reset = e.target.closest('[data-hof-reset]');
    if (reset) {
        e.preventDefault();
        store.clear();
        return;
    }

    // Active-filters chip ✕ — remove a single filter (one value, or whole
    // range/search facet when value is empty).
    const chip = e.target.closest('[data-hof-active-chip]');
    if (chip) {
        e.preventDefault();
        const facet = chip.getAttribute('data-hof-active-facet');
        const value = chip.getAttribute('data-hof-active-value') || '';
        if (!facet) return;

        const current = store.get()[facet];
        if (value === '') {
            // Range / search / whole-facet removal.
            store.set(facet, null);
        } else if (Array.isArray(current)) {
            store.set(facet, current.filter((v) => String(v) !== value));
        } else {
            // Scalar value — same as a whole-facet removal.
            store.set(facet, null);
        }
        return;
    }

});

// ── Helpers ───────────────────────────────────────────────────────────────

function debounce(fn, ms) {
    let t;
    return (...args) => {
        clearTimeout(t);
        t = setTimeout(() => fn(...args), ms);
    };
}
