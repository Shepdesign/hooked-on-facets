import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

async function loadFreshBin() {
    vi.resetModules();
    return import('../../public/src/bin.js');
}

// Minimal store stand-in: records _bin_ids writes the way the real Store would.
function fakeStore() {
    return {
        state: {},
        set(name, value) {
            if (value == null) delete this.state[name];
            else this.state[name] = value;
        },
        get() { return this.state; },
    };
}

const KEY = 'hof_bin_wp_';

function buildBin({ withButton = true, buttonId = '12', buttonLabel = 'Trail Runner' } = {}) {
    const button = withButton
        ? `<button data-hof-bin-add data-hof-bin-id="${buttonId}" data-hof-bin-label="${buttonLabel}">Save</button>`
        : '';
    return `
        ${button}
        <div class="hof-facet hof-facet-bin"
             data-hof-facet="bin" data-hof-display="saved_bin"
             data-hof-bin-key="${KEY}" data-hof-bin-dropzone>
            <p data-hof-bin-empty hidden></p>
            <ul data-hof-bin-items></ul>
            <div class="hof-bin-controls">
                <label><input type="checkbox" data-hof-bin-toggle>
                    <span>Show only saved (<span data-hof-bin-count>0</span>)</span></label>
                <button data-hof-bin-clear hidden>Clear bin</button>
            </div>
        </div>`;
}

const bin = () => document.querySelector('[data-hof-bin-key]');
const items = () => Array.from(document.querySelectorAll('[data-hof-bin-items] .hof-bin-item'));
const count = () => document.querySelector('[data-hof-bin-count]').textContent;
const clearBtn = () => document.querySelector('[data-hof-bin-clear]');
const emptyMsg = () => document.querySelector('[data-hof-bin-empty]');
const toggle = () => document.querySelector('[data-hof-bin-toggle]');

let controller;
let store;

beforeEach(() => {
    document.body.innerHTML = '';
    window.localStorage.clear();
    controller = new AbortController();
    store = fakeStore();
});

afterEach(() => {
    controller.abort();
    document.body.innerHTML = '';
    window.localStorage.clear();
});

describe('add / remove', () => {
    it('clicking an add button stores the item and renders a chip', async () => {
        document.body.innerHTML = buildBin();
        const { initBin } = await loadFreshBin();
        initBin(store, document.body, { signal: controller.signal });

        document.querySelector('[data-hof-bin-add]').click();

        expect(items()).toHaveLength(1);
        expect(items()[0].textContent).toContain('Trail Runner');
        expect(count()).toBe('1');
        expect(clearBtn().hidden).toBe(false);
        expect(emptyMsg().hidden).toBe(true);
        expect(JSON.parse(window.localStorage.getItem(KEY))).toEqual([{ id: '12', label: 'Trail Runner' }]);
    });

    it('clicking the same add button again toggles the item out', async () => {
        document.body.innerHTML = buildBin();
        const { initBin } = await loadFreshBin();
        initBin(store, document.body, { signal: controller.signal });

        const btn = document.querySelector('[data-hof-bin-add]');
        btn.click();
        btn.click();

        expect(items()).toHaveLength(0);
        expect(count()).toBe('0');
        expect(emptyMsg().hidden).toBe(false);
    });

    it('marks the add button as in-bin while the item is saved', async () => {
        document.body.innerHTML = buildBin();
        const { initBin } = await loadFreshBin();
        initBin(store, document.body, { signal: controller.signal });

        const btn = document.querySelector('[data-hof-bin-add]');
        btn.click();
        expect(btn.getAttribute('data-hof-bin-in')).toBe('1');
        btn.click();
        expect(btn.getAttribute('data-hof-bin-in')).toBeNull();
    });

    it('a chip ✕ removes that item', async () => {
        document.body.innerHTML = buildBin();
        const { initBin } = await loadFreshBin();
        initBin(store, document.body, { signal: controller.signal });

        document.querySelector('[data-hof-bin-add]').click();
        document.querySelector('[data-hof-bin-remove]').click();

        expect(items()).toHaveLength(0);
    });
});

describe('show only saved', () => {
    it('toggling on feeds _bin_ids to the store; off removes it', async () => {
        document.body.innerHTML = buildBin();
        const { initBin } = await loadFreshBin();
        initBin(store, document.body, { signal: controller.signal });
        document.querySelector('[data-hof-bin-add]').click();

        toggle().checked = true;
        toggle().dispatchEvent(new Event('change', { bubbles: true }));
        expect(store.get()._bin_ids).toEqual(['12']);

        toggle().checked = false;
        toggle().dispatchEvent(new Event('change', { bubbles: true }));
        expect(store.get()._bin_ids).toBeUndefined();
    });

    it('adding an item while show-only is on updates the store id set', async () => {
        document.body.innerHTML = buildBin();
        const { initBin } = await loadFreshBin();
        initBin(store, document.body, { signal: controller.signal });

        document.querySelector('[data-hof-bin-add]').click();
        toggle().checked = true;
        toggle().dispatchEvent(new Event('change', { bubbles: true }));
        expect(store.get()._bin_ids).toEqual(['12']);

        // Pin a second item via drop.
        const ev = new Event('drop', { bubbles: true });
        Object.defineProperty(ev, 'dataTransfer', {
            value: { getData: () => JSON.stringify({ id: '34', label: 'Court Classic' }) },
        });
        bin().dispatchEvent(ev);

        expect(store.get()._bin_ids).toEqual(['12', '34']);
        expect(items()).toHaveLength(2);
    });
});

describe('clear', () => {
    it('empties the bin and clears any active _bin_ids', async () => {
        document.body.innerHTML = buildBin();
        const { initBin } = await loadFreshBin();
        initBin(store, document.body, { signal: controller.signal });

        document.querySelector('[data-hof-bin-add]').click();
        toggle().checked = true;
        toggle().dispatchEvent(new Event('change', { bubbles: true }));

        clearBtn().click();

        expect(items()).toHaveLength(0);
        expect(count()).toBe('0');
        // No items left → show-only resolves to no restriction.
        expect(store.get()._bin_ids).toBeUndefined();
    });
});

describe('hydration', () => {
    it('renders items already in localStorage on init', async () => {
        window.localStorage.setItem(KEY, JSON.stringify([{ id: '7', label: 'Saved Earlier' }]));
        document.body.innerHTML = buildBin({ withButton: false });
        const { initBin } = await loadFreshBin();
        initBin(store, document.body, { signal: controller.signal });

        expect(items()).toHaveLength(1);
        expect(count()).toBe('1');
        expect(items()[0].textContent).toContain('Saved Earlier');
    });
});
