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

const store = new Store();
store.hydrateFromUrl();

initSwiper();

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

    if (display === 'checkbox' || display === 'swatch' || display === 'swiper') {
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

    if (display === 'venn') {
        // Combine both selection surfaces: circles flagged with
        // data-hof-selected (the visual Venn) and checked overflow
        // checkboxes (terms beyond the top 3). Either or both can change.
        const fromCircles = Array.from(
            facetEl.querySelectorAll('.hof-venn-circle[data-hof-selected]')
        ).map((c) => c.getAttribute('data-hof-venn-value'));
        const fromOverflow = Array.from(
            facetEl.querySelectorAll('.hof-venn-more-list input[type="checkbox"]:checked')
        ).map((cb) => cb.value);
        const values = Array.from(new Set([...fromCircles, ...fromOverflow]));
        store.set(name, values);
        return;
    }

    if (display === 'range') {
        const min = facetEl.querySelector('[data-hof-input="min"]')?.value ?? '';
        const max = facetEl.querySelector('[data-hof-input="max"]')?.value ?? '';
        store.set(name, { min, max });
        return;
    }

    if (display === 'search') {
        // change fires on blur — input handler below handles live typing.
        store.set(name, e.target.value);
    }
});

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
});

// Reset link hijack — clear all and refresh in place.
document.addEventListener('click', (e) => {
    const reset = e.target.closest('[data-hof-reset]');
    if (reset) {
        e.preventDefault();
        store.clear();
        return;
    }

    // Venn overlap region: toggle the comma-separated values as a group.
    // The data attribute carries 2 or 3 term slugs (e.g. "apparel,footwear");
    // clicking sets all of them; clicking again clears all of them.
    const vennRegion = e.target.closest('.hof-venn-region');
    if (vennRegion) {
        const facetEl = vennRegion.closest('[data-hof-facet]');
        const name    = facetEl?.getAttribute('data-hof-facet');
        const raw     = vennRegion.getAttribute('data-hof-venn-values') || '';
        const values  = raw.split(',').map((v) => v.trim()).filter(Boolean);
        if (!facetEl || !name || values.length === 0) return;

        const circles  = facetEl.querySelectorAll('.hof-venn-circle');
        const selected = readVennSelected(circles);
        const allActive = values.every((v) => selected.has(v));
        if (allActive) values.forEach((v) => selected.delete(v));
        else           values.forEach((v) => selected.add(v));

        applyVennSelected(circles, selected);
        store.set(name, Array.from(selected));
        return;
    }

    // Venn circle: toggle that term in the facet's selection. Hidden inputs
    // get regenerated on refresh; we use the data-hof-selected attribute on
    // the circles themselves as the source of truth between clicks.
    const vennCircle = e.target.closest('.hof-venn-circle');
    if (vennCircle) {
        const facetEl = vennCircle.closest('[data-hof-facet]');
        const name    = facetEl?.getAttribute('data-hof-facet');
        const value   = vennCircle.getAttribute('data-hof-venn-value');
        if (!facetEl || !name || !value) return;

        const circles  = facetEl.querySelectorAll('.hof-venn-circle');
        const selected = readVennSelected(circles);
        if (selected.has(value)) selected.delete(value);
        else selected.add(value);

        applyVennSelected(circles, selected);
        store.set(name, Array.from(selected));
    }
});

function readVennSelected(circles) {
    const set = new Set();
    circles.forEach((c) => {
        if (c.hasAttribute('data-hof-selected')) {
            set.add(c.getAttribute('data-hof-venn-value'));
        }
    });
    return set;
}

function applyVennSelected(circles, selected) {
    // Optimistic flip so the coral stroke shows before the round-trip.
    circles.forEach((c) => {
        const v = c.getAttribute('data-hof-venn-value');
        if (selected.has(v)) c.setAttribute('data-hof-selected', '1');
        else c.removeAttribute('data-hof-selected');
    });
}

// ── Helpers ───────────────────────────────────────────────────────────────

function debounce(fn, ms) {
    let t;
    return (...args) => {
        clearTimeout(t);
        t = setTimeout(() => fn(...args), ms);
    };
}
