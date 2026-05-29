// Spin-the-wheel runtime.
//
// The dial is cosmetic; selection is real radio inputs. Spin picks a random
// option, rotates the dial so that segment lands under the top pointer, and on
// the rotation's transitionend checks the radio + dispatches `change` — so the
// same main.js delegated handler that radio uses picks up the selection.
// Clicking an option directly is a native radio change; we only sync the
// dial + clear-button visuals for it. With JS off, the radiogroup still works.

const TURNS = 5; // full rotations per spin, for the spin feel

export function initSpinWheel(root = document, { signal } = {}) {
    const opts = signal ? { signal } : undefined;
    document.addEventListener('click', onClick, opts);
    document.addEventListener('change', onChange, opts);
    // After a refresh swaps the facet markup, restore the clear-button state.
    document.addEventListener('hof:refresh', (e) => {
        if (e.detail?.phase !== 'end') return;
        document.querySelectorAll('[data-hof-display="spin_the_wheel"]').forEach(syncClear);
    }, opts);
}

function onClick(e) {
    const spin = e.target.closest('[data-hof-wheel-spin]');
    if (spin) {
        const facet = spin.closest('[data-hof-display="spin_the_wheel"]');
        if (facet) spinWheel(facet);
        return;
    }
    const clear = e.target.closest('[data-hof-wheel-clear]');
    if (clear) {
        e.preventDefault();
        const facet = clear.closest('[data-hof-display="spin_the_wheel"]');
        if (facet) clearWheel(facet);
    }
}

function onChange(e) {
    const facet = e.target.closest?.('[data-hof-display="spin_the_wheel"]');
    if (!facet) return;
    if (!e.target.matches('input[type="radio"]')) return;
    // A direct click on an option — sync the visual to match.
    syncSelectedState(facet);
    syncClear(facet);
    const idx = radios(facet).indexOf(e.target);
    if (idx >= 0) rotateToIndex(facet, idx);
}

function spinWheel(facet) {
    if (facet.hasAttribute('data-hof-wheel-spinning')) return;
    const list = radios(facet);
    if (list.length === 0) return;

    const target = Math.floor(Math.random() * list.length);
    facet.setAttribute('data-hof-wheel-spinning', '1');
    rotateToIndex(facet, target);

    const dial = facet.querySelector('[data-hof-wheel-dial]');
    const finalize = () => {
        dial?.removeEventListener('transitionend', finalize);
        facet.removeAttribute('data-hof-wheel-spinning');
        const radio = list[target];
        if (radio && !radio.checked) {
            radio.checked = true;
            radio.dispatchEvent(new Event('change', { bubbles: true }));
        }
    };
    dial?.addEventListener('transitionend', finalize);
    // Fallback when no transition fires (reduced-motion / no layout in tests).
    setTimeout(finalize, 4500);
}

function clearWheel(facet) {
    let changed = false;
    radios(facet).forEach((r) => {
        if (r.checked) {
            r.checked = false;
            changed = true;
        }
    });
    syncSelectedState(facet);
    syncClear(facet);
    if (changed) {
        // One change drives a single refresh; main.js reads no checked radio
        // as an empty selection and clears the facet from the URL.
        radios(facet)[0]?.dispatchEvent(new Event('change', { bubbles: true }));
    }
}

// Rotate the dial so segment `idx` (of n) lands centered under the top
// pointer, always spinning forward by at least TURNS full rotations.
function rotateToIndex(facet, idx) {
    const dial = facet.querySelector('[data-hof-wheel-dial]');
    if (!dial) return;
    const n = radios(facet).length || 1;
    const seg = 360 / n;
    const prev = parseFloat(facet.getAttribute('data-hof-wheel-angle') || '0');
    const base = Math.ceil(prev / 360) * 360;
    const final = base + TURNS * 360 + (360 - (idx * seg + seg / 2));
    facet.setAttribute('data-hof-wheel-angle', String(final));
    dial.style.setProperty('--hof-wheel-rotation', `${final}deg`);
}

function syncSelectedState(facet) {
    facet.querySelectorAll('.hof-wheel-option').forEach((label) => {
        const r = label.querySelector('input[type="radio"]');
        if (r?.checked) label.setAttribute('data-hof-selected', '1');
        else label.removeAttribute('data-hof-selected');
    });
}

function syncClear(facet) {
    const clear = facet.querySelector('[data-hof-wheel-clear]');
    if (!clear) return;
    clear.hidden = !radios(facet).some((r) => r.checked);
}

function radios(facet) {
    return Array.from(facet.querySelectorAll('input[type="radio"]'));
}
