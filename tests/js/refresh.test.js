import { describe, expect, it } from 'vitest';
import { patchSwatch, patchSwiper } from '../../public/src/refresh.js';

// Build a facet wrapper as either the live DOM copy (`current`) or the
// freshly server-rendered copy (`incoming`). `included` controls whether
// the server marks a value as right-swiped (i.e. present in the URL).
function buildFacet({ values, counts = {}, included = [] }) {
    const includedSet = new Set(included);
    const html = values.map((value) => {
        const swiped = includedSet.has(value) ? 'data-hof-swiped="right" hidden' : '';
        const checked = includedSet.has(value) ? 'checked' : '';
        const count = counts[value] ?? 0;
        return `
            <div class="hof-swiper-card" data-hof-swiper-card data-hof-swiper-value="${value}" ${swiped}>
                <input type="checkbox" name="hof[color][]" value="${value}" ${checked}>
                <span class="hof-facet-count" data-hof-count="${value}">${count}</span>
            </div>
        `;
    }).join('');

    const wrapper = document.createElement('div');
    wrapper.setAttribute('data-hof-facet', 'color');
    wrapper.setAttribute('data-hof-display', 'swiper');
    wrapper.innerHTML = html;
    return wrapper;
}

function card(root, value) {
    return root.querySelector(`[data-hof-swiper-value="${value}"]`);
}

describe('patchSwiper — counts', () => {
    it('updates count text on existing cards from the incoming copy', async () => {
        const current = buildFacet({ values: ['red', 'blue'], counts: { red: 12, blue: 8 } });
        const incoming = buildFacet({ values: ['red', 'blue'], counts: { red: 3, blue: 0 } });

        patchSwiper(current, incoming);

        expect(card(current, 'red').querySelector('[data-hof-count]').textContent).toBe('3');
        expect(card(current, 'blue').querySelector('[data-hof-count]').textContent).toBe('0');
    });
});

describe('patchSwiper — right-swipe reconcile', () => {
    it('marks card right-swiped + hidden + checked when the server says included', () => {
        const current = buildFacet({ values: ['red'], counts: { red: 12 } });
        const incoming = buildFacet({ values: ['red'], counts: { red: 12 }, included: ['red'] });

        patchSwiper(current, incoming);

        const c = card(current, 'red');
        expect(c.getAttribute('data-hof-swiped')).toBe('right');
        expect(c.hidden).toBe(true);
        expect(c.querySelector('input').checked).toBe(true);
    });

    it('restores card when server clears it but local has it right-swiped (popstate / reset)', () => {
        const current = buildFacet({ values: ['red'], counts: { red: 12 }, included: ['red'] });
        // Sanity-check the fixture set us up as right-swiped + hidden.
        expect(card(current, 'red').hidden).toBe(true);
        card(current, 'red').style.setProperty('--hof-swipe-x', '2000');

        const incoming = buildFacet({ values: ['red'], counts: { red: 12 } });

        patchSwiper(current, incoming);

        const c = card(current, 'red');
        expect(c.getAttribute('data-hof-swiped')).toBeNull();
        expect(c.hidden).toBe(false);
        expect(c.style.getPropertyValue('--hof-swipe-x')).toBe('');
        expect(c.querySelector('input').checked).toBe(false);
    });

    it('preserves a local left-swipe (skip) when the server has no opinion on the value', () => {
        const current = buildFacet({ values: ['red'] });
        const c = card(current, 'red');
        // Simulate a left-swipe the user already performed locally.
        c.setAttribute('data-hof-swiped', 'left');

        const incoming = buildFacet({ values: ['red'] });

        patchSwiper(current, incoming);

        expect(c.getAttribute('data-hof-swiped')).toBe('left');
        expect(c.querySelector('input').checked).toBe(false);
    });
});

// ── patchSwatch ───────────────────────────────────────────────────────

function buildSwatchFacet({ values, counts = {}, weights = {}, tooltips = {}, selected = [] } = {}) {
    const selectedSet = new Set(selected);
    const html = values.map((value) => {
        const isSelected = selectedSet.has(value);
        const count = counts[value] ?? 0;
        const weight = weights[value];
        const tooltip = tooltips[value] ?? `${value} · ${count}`;
        const style = weight !== undefined ? `style="--hof-swatch-weight: ${weight};"` : '';
        const aria = `aria-label="${value}, ${count} items"`;
        return `
            <label class="hof-facet-swatch-tile"
                   ${style}
                   data-hof-swatch-value="${value}"
                   data-hof-tooltip="${tooltip}"
                   ${isSelected ? 'data-hof-selected="1"' : ''}>
                <input type="checkbox" name="hof[color][]" value="${value}" ${aria} ${isSelected ? 'checked' : ''}>
                <span class="hof-facet-count" data-hof-count="${value}">${count}</span>
            </label>
        `;
    }).join('');

    const wrapper = document.createElement('div');
    wrapper.setAttribute('data-hof-facet', 'color');
    wrapper.setAttribute('data-hof-display', 'swatch');
    wrapper.innerHTML = html;
    return wrapper;
}

function tile(root, value) {
    return root.querySelector(`[data-hof-swatch-value="${value}"]`);
}

describe('patchSwatch — counts and weight', () => {
    it('updates count text from the incoming copy', () => {
        const current  = buildSwatchFacet({ values: ['red'], counts: { red: 12 } });
        const incoming = buildSwatchFacet({ values: ['red'], counts: { red: 3 } });

        patchSwatch(current, incoming);

        expect(tile(current, 'red').querySelector('[data-hof-count]').textContent).toBe('3');
    });

    it('updates --hof-swatch-weight so the tile resizes in place', () => {
        const current  = buildSwatchFacet({ values: ['red'], weights: { red: 1 } });
        const incoming = buildSwatchFacet({ values: ['red'], weights: { red: 0.25 } });

        patchSwatch(current, incoming);

        expect(tile(current, 'red').style.getPropertyValue('--hof-swatch-weight')).toBe('0.25');
    });
});

describe('patchSwatch — tooltip + a11y sync with count', () => {
    it('rewrites data-hof-tooltip when the count changes', () => {
        const current = buildSwatchFacet({
            values: ['red'],
            counts: { red: 12 },
            tooltips: { red: 'Red · 12' },
        });
        const incoming = buildSwatchFacet({
            values: ['red'],
            counts: { red: 3 },
            tooltips: { red: 'Red · 3' },
        });

        patchSwatch(current, incoming);

        // Tooltip surfaces full term + count on hover; stale text would
        // claim 12 matches when the filter has narrowed to 3.
        expect(tile(current, 'red').getAttribute('data-hof-tooltip')).toBe('Red · 3');
    });

    it('rewrites the input aria-label when the count changes', () => {
        const current  = buildSwatchFacet({ values: ['red'], counts: { red: 12 } });
        const incoming = buildSwatchFacet({ values: ['red'], counts: { red: 3 } });

        patchSwatch(current, incoming);

        expect(tile(current, 'red').querySelector('input').getAttribute('aria-label'))
            .toBe('red, 3 items');
    });
});

describe('patchSwatch — selected state', () => {
    it('marks a previously unselected tile as selected when the server says so', () => {
        const current  = buildSwatchFacet({ values: ['red'] });
        const incoming = buildSwatchFacet({ values: ['red'], selected: ['red'] });

        patchSwatch(current, incoming);

        const t = tile(current, 'red');
        expect(t.getAttribute('data-hof-selected')).toBe('1');
        expect(t.querySelector('input').checked).toBe(true);
    });

    it('clears the selected attribute when the server has dropped the value', () => {
        const current  = buildSwatchFacet({ values: ['red'], selected: ['red'] });
        const incoming = buildSwatchFacet({ values: ['red'] });

        patchSwatch(current, incoming);

        const t = tile(current, 'red');
        expect(t.getAttribute('data-hof-selected')).toBeNull();
        expect(t.querySelector('input').checked).toBe(false);
    });
});
