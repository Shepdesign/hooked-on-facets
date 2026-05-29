import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

// initSpinWheel attaches listeners to `document`. resetModules per test keeps
// the module pristine and avoids cross-test listener leaks.
async function loadFreshSpin() {
    vi.resetModules();
    return import('../../public/src/spin.js');
}

function buildWheel({ values = ['acme', 'globex', 'initech'], selected = '' } = {}) {
    const options = values.map((value) => {
        const checked = value === selected ? 'checked' : '';
        const sel = value === selected ? ' data-hof-selected="1"' : '';
        return `
            <li class="hof-wheel-option-item">
                <label class="hof-wheel-option"${sel}>
                    <input type="radio" class="hof-wheel-input" name="hof[brand]" value="${value}"
                           data-hof-wheel-value="${value}" ${checked}>
                    <span class="hof-wheel-option-name">${value}</span>
                    <span class="hof-facet-count" data-hof-count="${value}">12</span>
                </label>
            </li>`;
    }).join('');

    return `
        <div class="hof-facet hof-facet-wheel"
             data-hof-facet="brand"
             data-hof-display="spin_the_wheel"
             style="--hof-wheel-n: ${values.length};">
            <div class="hof-wheel">
                <span class="hof-wheel-pointer"></span>
                <div class="hof-wheel-dial" data-hof-wheel-dial></div>
                <button type="button" class="hof-wheel-spin" data-hof-wheel-spin>Spin</button>
            </div>
            <ul class="hof-wheel-options" role="radiogroup">${options}</ul>
            <button type="button" class="hof-wheel-clear" data-hof-wheel-clear ${selected ? '' : 'hidden'}>Clear</button>
        </div>`;
}

const facet = () => document.querySelector('[data-hof-facet="brand"]');
const radios = () => Array.from(document.querySelectorAll('input[type="radio"]'));
const dial = () => document.querySelector('[data-hof-wheel-dial]');
const clearBtn = () => document.querySelector('[data-hof-wheel-clear]');

// The dial finalizes the spin on transitionend (or a 4.5s fallback).
function flushTransition() {
    dial().dispatchEvent(new Event('transitionend'));
}

let controller;

beforeEach(() => {
    document.body.innerHTML = '';
    controller = new AbortController();
});

afterEach(() => {
    controller.abort();
    document.body.innerHTML = '';
    vi.restoreAllMocks();
});

describe('spin', () => {
    it('lands on a radio, checks it, and dispatches change after the dial settles', async () => {
        document.body.innerHTML = buildWheel();
        const { initSpinWheel } = await loadFreshSpin();
        initSpinWheel(document.body, { signal: controller.signal });

        // Force the random landing onto index 1 (globex).
        vi.spyOn(Math, 'random').mockReturnValue(0.5); // floor(0.5 * 3) = 1
        const onChange = vi.fn();
        document.addEventListener('change', onChange);

        document.querySelector('[data-hof-wheel-spin]').click();
        // Mid-spin: locked, not yet committed.
        expect(facet().hasAttribute('data-hof-wheel-spinning')).toBe(true);
        expect(radios().some((r) => r.checked)).toBe(false);

        flushTransition();

        expect(facet().hasAttribute('data-hof-wheel-spinning')).toBe(false);
        expect(radios()[1].checked).toBe(true);
        expect(onChange).toHaveBeenCalled();
    });

    it('ignores a second spin while one is in flight', async () => {
        document.body.innerHTML = buildWheel();
        const { initSpinWheel } = await loadFreshSpin();
        initSpinWheel(document.body, { signal: controller.signal });
        vi.spyOn(Math, 'random').mockReturnValue(0);

        const spin = document.querySelector('[data-hof-wheel-spin]');
        spin.click();
        const angleAfterFirst = facet().getAttribute('data-hof-wheel-angle');
        spin.click(); // should be a no-op — still spinning

        expect(facet().getAttribute('data-hof-wheel-angle')).toBe(angleAfterFirst);
    });

    it('advances the dial angle forward on each spin', async () => {
        document.body.innerHTML = buildWheel();
        const { initSpinWheel } = await loadFreshSpin();
        initSpinWheel(document.body, { signal: controller.signal });
        vi.spyOn(Math, 'random').mockReturnValue(0);

        document.querySelector('[data-hof-wheel-spin]').click();
        const first = parseFloat(facet().getAttribute('data-hof-wheel-angle'));
        flushTransition();
        document.querySelector('[data-hof-wheel-spin]').click();
        const second = parseFloat(facet().getAttribute('data-hof-wheel-angle'));

        expect(second).toBeGreaterThan(first);
    });
});

describe('direct selection', () => {
    it('clicking an option marks it selected and reveals Clear', async () => {
        document.body.innerHTML = buildWheel();
        const { initSpinWheel } = await loadFreshSpin();
        initSpinWheel(document.body, { signal: controller.signal });

        const r = radios()[2];
        r.checked = true;
        r.dispatchEvent(new Event('change', { bubbles: true }));

        expect(r.closest('.hof-wheel-option').getAttribute('data-hof-selected')).toBe('1');
        expect(clearBtn().hidden).toBe(false);
    });
});

describe('clear', () => {
    it('unchecks the selection, fires one change, and hides Clear', async () => {
        document.body.innerHTML = buildWheel({ selected: 'acme' });
        const { initSpinWheel } = await loadFreshSpin();
        initSpinWheel(document.body, { signal: controller.signal });

        expect(radios()[0].checked).toBe(true);
        const onChange = vi.fn();
        document.addEventListener('change', onChange);

        clearBtn().click();

        expect(radios().some((r) => r.checked)).toBe(false);
        expect(onChange).toHaveBeenCalledTimes(1);
        expect(clearBtn().hidden).toBe(true);
    });
});

describe('empty facet', () => {
    it('spin is a no-op when there are no options', async () => {
        document.body.innerHTML = `
            <div class="hof-facet hof-facet-wheel" data-hof-facet="brand" data-hof-display="spin_the_wheel">
                <div class="hof-wheel">
                    <div class="hof-wheel-dial" data-hof-wheel-dial></div>
                    <button type="button" data-hof-wheel-spin>Spin</button>
                </div>
            </div>`;
        const { initSpinWheel } = await loadFreshSpin();
        initSpinWheel(document.body, { signal: controller.signal });

        document.querySelector('[data-hof-wheel-spin]').click();

        expect(facet().hasAttribute('data-hof-wheel-spinning')).toBe(false);
    });
});
