import { describe, expect, it } from 'vitest';
import { patchSwiper } from '../../public/src/refresh.js';

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
