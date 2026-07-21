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
import { initSpinWheel } from './spin.js';
import { initBin } from './bin.js';
import { initAsk } from './ask.js';
import { initVisualDna } from './visual-dna.js';
import { initPagination } from './pagination.js';

const store = new Store();
store.hydrateFromUrl();

initSwiper();
initSpinWheel();
initBin(store);
initAsk(store);
initVisualDna(store);
initPagination();
bootPrettyLinks();

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

    // refresh.js's swapFacets() replaces a facet's whole innerHTML (checkbox,
    // radio, hierarchy, dropdown) with freshly server-rendered markup on
    // every completed refresh — including brand-new, untouched
    // .hof-facet-link / .hof-facet-seo-links elements. Re-run the sweep so
    // they don't reappear tabbable/visible. Swatch/swiper facets patch counts
    // in place instead of replacing innerHTML, so their links are unaffected
    // and re-running the sweep on them is simply a no-op.
    if (phase === 'end') syncPrettyLinkA11y();
});

// ── DOM events → state ────────────────────────────────────────────────────

document.addEventListener('change', (e) => {
    const facetEl = e.target.closest('[data-hof-facet]');
    if (!facetEl) return;

    const name = facetEl.getAttribute('data-hof-facet');
    const display = facetEl.getAttribute('data-hof-display');
    if (!name) return;

    if (display === 'checkbox' || display === 'swatch' || display === 'swiper' || display === 'hierarchy' || display === 'matrix') {
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

        if (display === 'matrix') {
            // Same optimistic flip for matrix rows — the selected-state dot
            // updates immediately; refresh() reconciles counts.
            facetEl.querySelectorAll('.hof-matrix-cell').forEach((cell) => {
                const cb = cell.querySelector('input[type="checkbox"]');
                if (cb?.checked) cell.setAttribute('data-hof-selected', '1');
                else cell.removeAttribute('data-hof-selected');
            });
        }

        store.set(name, values);
        return;
    }

    if (display === 'radio' || display === 'spin_the_wheel') {
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

// ── Pretty-link boot ─────────────────────────────────────────────────────
//
// The server renders crawlable <a class="hof-facet-link"> anchors as SIBLINGS
// of each option's <label> (never inside it — see Renderer.php) so no-JS
// visitors and crawlers get real working links. Once this runtime owns
// interaction, those anchors should route through the same store → AJAX +
// pushState path as clicking the underlying input, the dropdown's visible SEO
// fallback list should hide, and the anchors should leave the tab order (the
// inputs are the interactive controls, not their duplicate labels).
//
// Every pretty_link() call site wraps its anchor (or the dropdown's SEO <li>)
// in an <li> — checkbox/radio options, both hierarchy row shapes, and swatch
// tiles all use <li> as the nearest common ancestor that also contains the
// option's <input>; the dropdown's hidden-list <li> contains no input, which
// is exactly how bots/no-JS visitors are meant to fall through to a real
// navigation instead of being intercepted.

/** Idempotent — safe to call again after AJAX re-renders new facet markup. */
function syncPrettyLinkA11y() {
    for (const list of document.querySelectorAll('.hof-facet-seo-links')) {
        list.hidden = true;
    }
    for (const link of document.querySelectorAll('.hof-facet-link')) {
        link.setAttribute('tabindex', '-1');
    }
}

function bootPrettyLinks() {
    syncPrettyLinkA11y();

    // Delegated on document, registered once: keeps working for anchors
    // swapped in by later refreshes without re-attaching.
    document.addEventListener('click', (e) => {
        const link = e.target.closest('.hof-facet-link');
        if (!link) return;
        const input = link.closest('li')?.querySelector('input');
        if (!input) return; // dropdown SEO list — let no-JS/bots navigate
        e.preventDefault();
        input.click();
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
