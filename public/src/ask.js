// Ask — natural-language intent facet.
//
// Conversation is *stateless* on the server: every turn ships the full prior
// constraint set, and the server returns the full updated set. We track that
// state per facet element in a WeakMap so multiple Ask facets on one page
// don't bleed into each other (unlikely, but cheap to be correct).
//
// Removing a chip drops the matching constraint locally (no API call) and
// also clears the corresponding filter via the store. The next turn carries
// the trimmed prior_state, so the AI sees the user's correction.
//
// Listeners are attached to the facet wrapper so they survive refresh.js's
// innerHTML swap of the facet content after each filter change.

const stateByFacet = new WeakMap();

// Placeholders that cycle when the input is empty and unfocused. Designed
// to teach shoppers what kind of language works.
const EXAMPLE_PLACEHOLDERS = [
    'comfy red shoes under $50',
    'a gift for my dad\'s workshop',
    'cozy stuff in dark colors',
    'something I\'d wear hiking',
];

// How long an exiting chip transitions before we remove it from the DOM.
const CHIP_EXIT_MS = 220;

// How long the warm-glow pulse stays on a chip that just arrived.
const CHIP_PULSE_MS = 800;

export function initAsk(store) {
    const wired = new WeakSet();

    const wire = () => {
        document.querySelectorAll('.hof-facet-ask').forEach((facetEl) => {
            if (wired.has(facetEl)) return;
            wired.add(facetEl);
            attach(facetEl, store);
        });
    };

    wire();
    document.addEventListener('hof:refresh', (e) => {
        if (e.detail?.phase === 'end') {
            wire();
            // After a refresh, the facet's heard-state may have been rendered
            // back as part of the DOM swap. Re-render from our in-memory state.
            document.querySelectorAll('.hof-facet-ask').forEach((el) => {
                const state = stateByFacet.get(el);
                if (state) renderChips(el, state);
            });
        }
    });
}

function attach(facetEl, store) {
    stateByFacet.set(facetEl, {});

    facetEl.addEventListener('submit', (e) => {
        if (!e.target.matches('[data-hof-ask-form]')) return;
        e.preventDefault();
        runTurn(facetEl, store);
    });

    facetEl.addEventListener('click', (e) => {
        const chip = e.target.closest('[data-hof-ask-chip]');
        if (chip && !chip.classList.contains('is-exiting')) {
            e.preventDefault();
            removeChip(facetEl, store, chip);
            return;
        }
        const reset = e.target.closest('[data-hof-ask-reset]');
        if (reset) {
            e.preventDefault();
            resetAsk(facetEl, store);
        }
    });

    // Keyboard parity for chip removal — Enter / Space when chip has focus.
    facetEl.addEventListener('keydown', (e) => {
        if (e.key !== 'Enter' && e.key !== ' ') return;
        const chip = e.target.closest('[data-hof-ask-chip]');
        if (chip && !chip.classList.contains('is-exiting')) {
            e.preventDefault();
            removeChip(facetEl, store, chip);
        }
    });

    startPlaceholderCycle(facetEl);
}

async function runTurn(facetEl, store) {
    const input  = facetEl.querySelector('[data-hof-ask-input]');
    const status = facetEl.querySelector('[data-hof-ask-status]');
    const query  = (input?.value || '').trim();
    if (query === '') {
        showStatus(status, 'error', 'Tell it what you want.');
        return;
    }

    const cfg = window.hofPublic || {};
    if (!cfg.restUrl) {
        showStatus(status, 'error', 'Ask backend not available.');
        return;
    }

    const priorState = { ...(stateByFacet.get(facetEl) || {}) };

    setLoading(facetEl, true);
    // Show optimistic skeletons immediately so the user sees motion before
    // the network round-trip finishes.
    renderSkeletons(facetEl, 3);
    hideStatus(status);

    try {
        const res = await fetch(`${cfg.restUrl}ask`, {
            method:  'POST',
            headers: {
                'Content-Type': 'application/json',
                ...(cfg.nonce ? { 'X-WP-Nonce': cfg.nonce } : {}),
            },
            body: JSON.stringify({ query, prior_state: priorState }),
        });
        const body = await res.json().catch(() => ({}));

        if (!res.ok || !body.ok) {
            renderChips(facetEl, priorState); // Roll skeletons back.
            showStatus(status, 'error', friendlyError(body));
            return;
        }

        const nextState   = body.filters || {};
        const changedKeys = diffStateKeys(priorState, nextState);
        stateByFacet.set(facetEl, nextState);
        renderChips(facetEl, nextState, changedKeys);
        syncStoreFromState(store, priorState, nextState);

        if (Object.keys(nextState).length === 0) {
            showStatus(status, 'warn',
                'Didn\'t catch any constraints. Try mentioning a color, category, or price.');
        } else {
            hideStatus(status);
            input.value = '';
        }
    } catch (err) {
        renderChips(facetEl, priorState);
        showStatus(status, 'error', err?.message || 'Ask failed.');
    } finally {
        setLoading(facetEl, false);
    }
}

function removeChip(facetEl, store, chipEl) {
    const facet = chipEl.getAttribute('data-hof-ask-facet');
    const which = chipEl.getAttribute('data-hof-ask-kind'); // 'value' | 'min' | 'max'
    const value = chipEl.getAttribute('data-hof-ask-value') || '';
    if (!facet) return;

    // Play the exit animation, then commit the state change. The state edit
    // is computed up front so we don't depend on the chip element after the
    // delay completes.
    chipEl.classList.add('is-exiting');

    const state = { ...(stateByFacet.get(facetEl) || {}) };
    const current = state[facet];

    if (which === 'value' && Array.isArray(current)) {
        const next = current.filter((v) => String(v) !== value);
        if (next.length > 0) {
            state[facet] = next;
            store.set(facet, next);
        } else {
            delete state[facet];
            store.set(facet, null);
        }
    } else if ((which === 'min' || which === 'max') && current && typeof current === 'object') {
        const next = { ...current };
        delete next[which];
        if (next.min == null && next.max == null) {
            delete state[facet];
            store.set(facet, null);
        } else {
            state[facet] = next;
            store.set(facet, { min: next.min ?? '', max: next.max ?? '' });
        }
    } else {
        delete state[facet];
        store.set(facet, null);
    }

    stateByFacet.set(facetEl, state);
    setTimeout(() => renderChips(facetEl, state), CHIP_EXIT_MS);
}

function resetAsk(facetEl, store) {
    const state = stateByFacet.get(facetEl) || {};
    for (const facet of Object.keys(state)) {
        store.set(facet, null);
    }
    stateByFacet.set(facetEl, {});
    renderChips(facetEl, {});
    const input = facetEl.querySelector('[data-hof-ask-input]');
    if (input) {
        input.value = '';
        input.focus();
    }
    hideStatus(facetEl.querySelector('[data-hof-ask-status]'));
}

function syncStoreFromState(store, before, after) {
    // Apply every key in `after` (store coalesces successive sets into one refetch).
    for (const [name, value] of Object.entries(after)) {
        if (Array.isArray(value)) {
            store.set(name, value);
        } else if (value && typeof value === 'object') {
            store.set(name, { min: value.min ?? '', max: value.max ?? '' });
        } else {
            store.set(name, value);
        }
    }
    // Clear any constraint that existed before but is no longer in after.
    for (const name of Object.keys(before)) {
        if (!(name in after)) store.set(name, null);
    }
}

function renderChips(facetEl, state, changedKeys = null) {
    const heard = facetEl.querySelector('[data-hof-ask-heard]');
    const list  = facetEl.querySelector('[data-hof-ask-chips]');
    if (!heard || !list) return;

    const chips = stateToChips(state);
    list.innerHTML = '';

    if (chips.length === 0) {
        heard.hidden = true;
        return;
    }

    heard.hidden = false;
    for (const chip of chips) {
        const li = document.createElement('li');
        li.className = 'hof-ask-chip';
        li.setAttribute('data-hof-ask-chip', '');
        li.setAttribute('data-hof-ask-facet', chip.facet);
        li.setAttribute('data-hof-ask-kind', chip.kind);
        if (chip.value !== undefined) li.setAttribute('data-hof-ask-value', chip.value);
        li.setAttribute('role', 'button');
        li.setAttribute('tabindex', '0');
        li.setAttribute('aria-label', `Remove ${chip.facet}: ${chip.label}`);
        li.innerHTML = `<span class="hof-ask-chip-label">${escapeHtml(chip.facet)}: ${escapeHtml(chip.label)}</span><span class="hof-ask-chip-x" aria-hidden="true">×</span>`;

        if (changedKeys && changedKeys.has(chipKey(chip))) {
            li.classList.add('is-new');
            setTimeout(() => li.classList.remove('is-new'), CHIP_PULSE_MS);
        }

        list.appendChild(li);
    }
}

function renderSkeletons(facetEl, count) {
    const heard = facetEl.querySelector('[data-hof-ask-heard]');
    const list  = facetEl.querySelector('[data-hof-ask-chips]');
    if (!heard || !list) return;
    heard.hidden = false;
    list.innerHTML = '';
    for (let i = 0; i < count; i++) {
        const li = document.createElement('li');
        li.className = 'hof-ask-chip hof-ask-skeleton';
        li.setAttribute('aria-hidden', 'true');
        li.style.setProperty('--hof-skel-delay', `${i * 120}ms`);
        li.innerHTML = '<span class="hof-ask-skeleton-shimmer"></span>';
        list.appendChild(li);
    }
}

function diffStateKeys(before, after) {
    // Returns a Set of chipKey strings for chips that are new or changed.
    const result      = new Set();
    const beforeChips = stateToChips(before);
    const afterChips  = stateToChips(after);
    const beforeKeys  = new Set(beforeChips.map(chipKey));
    for (const chip of afterChips) {
        const key = chipKey(chip);
        if (!beforeKeys.has(key)) result.add(key);
    }
    return result;
}

function chipKey(chip) {
    return `${chip.facet}|${chip.kind}|${chip.value ?? chip.label}`;
}

function stateToChips(state) {
    const chips = [];
    for (const [facet, value] of Object.entries(state)) {
        if (Array.isArray(value)) {
            for (const v of value) chips.push({ facet, kind: 'value', value: String(v), label: String(v) });
        } else if (value && typeof value === 'object') {
            if (value.min != null && value.min !== '') chips.push({ facet, kind: 'min', label: `≥${value.min}` });
            if (value.max != null && value.max !== '') chips.push({ facet, kind: 'max', label: `≤${value.max}` });
        } else if (value !== null && value !== undefined && value !== '') {
            chips.push({ facet, kind: 'value', value: String(value), label: String(value) });
        }
    }
    return chips;
}

function setLoading(facetEl, loading) {
    const form   = facetEl.querySelector('[data-hof-ask-form]');
    const submit = facetEl.querySelector('[data-hof-ask-submit]');
    if (!form || !submit) return;
    if (loading) {
        form.classList.add('is-loading');
        submit.disabled = true;
        submit.classList.add('is-loading');
        const iconEl = submit.querySelector('span[aria-hidden]');
        if (iconEl) {
            submit.dataset.iconRest = iconEl.textContent || '';
            iconEl.textContent = '✦';
        }
    } else {
        form.classList.remove('is-loading');
        submit.disabled = false;
        submit.classList.remove('is-loading');
        const iconEl = submit.querySelector('span[aria-hidden]');
        if (iconEl) {
            iconEl.textContent = submit.dataset.iconRest || '▶';
            delete submit.dataset.iconRest;
        }
    }
}

function startPlaceholderCycle(facetEl) {
    const input = facetEl.querySelector('[data-hof-ask-input]');
    if (!input) return;

    // Honor an admin-configured placeholder — only cycle when the renderer
    // emitted the default. (The defaults strings live in Renderer + here;
    // any other value is the admin's chosen copy.)
    const DEFAULTS = new Set(['Describe what you\'re looking for…']);
    const original = input.getAttribute('placeholder') || '';
    if (original && !DEFAULTS.has(original)) return;

    let i = 0;
    let paused = false;

    const tick = () => {
        if (paused) return;
        if (document.activeElement === input) return;
        if (input.value) return;
        const list     = facetEl.querySelector('[data-hof-ask-chips]');
        const hasChips = list && list.children.length > 0
            && !list.querySelector('.hof-ask-skeleton');
        if (hasChips) return;

        input.classList.add('is-placeholder-fade');
        setTimeout(() => {
            i = (i + 1) % EXAMPLE_PLACEHOLDERS.length;
            input.setAttribute('placeholder', `Try: ${EXAMPLE_PLACEHOLDERS[i]}`);
            input.classList.remove('is-placeholder-fade');
        }, 180);
    };

    setInterval(tick, 3500);

    input.addEventListener('focus', () => { paused = true; });
    input.addEventListener('blur',  () => { paused = false; });
}

function friendlyError(body) {
    const code = body?.error_code || '';
    const msg  = body?.error || '';
    if (code === 'no_api_key')           return 'Ask isn\'t available right now.';
    if (code === 'no_facets')            return 'Nothing to ask about yet.';
    if (code === 'empty_query')          return 'Tell it what you want.';
    if (code === 'authentication_error') return 'Ask isn\'t available right now.';
    if (code === 'retry_exhausted')      return 'Ask is busy. Try again in a moment.';
    if (/credit balance|insufficient_funds|billing/i.test(msg)) {
        return 'Ask isn\'t available right now.';
    }
    return 'Ask failed. Try again.';
}

function showStatus(el, kind, text) {
    if (!el) return;
    el.hidden = false;
    el.textContent = text;
    el.setAttribute('data-hof-ask-status-kind', kind);
}

function hideStatus(el) {
    if (!el) return;
    el.hidden = true;
    el.textContent = '';
    el.removeAttribute('data-hof-ask-status-kind');
}

function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, (c) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    }[c]));
}
