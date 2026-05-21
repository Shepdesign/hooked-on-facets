// AI search public runtime — submit a natural-language query to the
// /wp-json/hof/v1/ai-search endpoint, then apply the structured filters
// it returns via the store. Each filter triggers store.set(), which the
// 0ms-debounced subscriber in main.js coalesces into a single refetch.
//
// Listeners attach to the facet wrapper so they survive refresh.js's
// innerHTML swap of the facet content after each filter change.

export function initAiSearch(store) {
    const wired = new WeakSet();

    const wire = () => {
        document.querySelectorAll('.hof-facet-ai-search').forEach((facetEl) => {
            if (wired.has(facetEl)) return;
            wired.add(facetEl);
            attach(facetEl, store);
        });
    };

    wire();
    document.addEventListener('hof:refresh', (e) => {
        if (e.detail?.phase === 'end') wire();
    });
}

function attach(facetEl, store) {
    facetEl.addEventListener('submit', async (e) => {
        if (!e.target.matches('[data-hof-ai-form]')) return;
        e.preventDefault();
        await runQuery(facetEl, store);
    });
}

async function runQuery(facetEl, store) {
    const input  = facetEl.querySelector('[data-hof-ai-input]');
    const submit = facetEl.querySelector('[data-hof-ai-submit]');
    const status = facetEl.querySelector('[data-hof-ai-status]');
    const query  = (input?.value || '').trim();
    if (query === '') {
        showStatus(status, 'error', 'Type something to search for.');
        return;
    }

    const cfg = window.hofPublic || {};
    if (!cfg.restUrl) {
        showStatus(status, 'error', 'Search backend not available.');
        return;
    }

    submit.disabled = true;
    submit.classList.add('is-loading');
    showStatus(status, 'loading', 'Thinking…');

    try {
        const res = await fetch(`${cfg.restUrl}ai-search`, {
            method:  'POST',
            headers: {
                'Content-Type': 'application/json',
                ...(cfg.nonce ? { 'X-WP-Nonce': cfg.nonce } : {}),
            },
            body: JSON.stringify({ query }),
        });
        const body = await res.json().catch(() => ({}));

        if (!res.ok || !body.ok) {
            showStatus(status, 'error', friendlyError(body));
            return;
        }

        const filters = body.filters || {};
        const names   = Object.keys(filters);
        if (names.length === 0) {
            showStatus(status, 'warn', 'Couldn\'t map that to any filters. Try mentioning a category, brand, color, size, or price.');
            return;
        }

        applyFilters(store, filters);
        showStatus(status, 'ok', `Applied ${names.length} filter${names.length === 1 ? '' : 's'}: ${humanize(filters)}`);
    } catch (err) {
        showStatus(status, 'error', err?.message || 'Search failed.');
    } finally {
        submit.disabled = false;
        submit.classList.remove('is-loading');
    }
}

function applyFilters(store, filters) {
    // Each store.set fires the subscriber, but main.js coalesces successive
    // notifies on a 0ms timeout, so multiple sets → one refetch.
    for (const [name, value] of Object.entries(filters)) {
        store.set(name, value);
    }
}

function humanize(filters) {
    const parts = [];
    for (const [name, value] of Object.entries(filters)) {
        if (Array.isArray(value)) {
            parts.push(`${name}=${value.join('/')}`);
        } else if (value && typeof value === 'object') {
            const bits = [];
            if (value.min !== undefined) bits.push(`≥${value.min}`);
            if (value.max !== undefined) bits.push(`≤${value.max}`);
            parts.push(`${name} ${bits.join(' ')}`);
        } else {
            parts.push(`${name}=${value}`);
        }
    }
    return parts.join(', ');
}

function friendlyError(body) {
    const code = body?.error_code || '';
    const msg  = body?.error || '';
    if (code === 'no_api_key')           return 'Search isn\'t available right now.';
    if (code === 'no_facets')            return 'Nothing to search yet.';
    if (code === 'empty_query')          return 'Type something to search for.';
    if (code === 'authentication_error') return 'Search isn\'t available right now.';
    if (code === 'retry_exhausted')      return 'Search service is busy. Try again in a moment.';
    // Anthropic billing-balance error — the BYOK admin needs to top up.
    // Don't leak the upstream provider language to end users.
    if (/credit balance|insufficient_funds|billing/i.test(msg)) {
        return 'Search isn\'t available right now.';
    }
    // Generic upstream failure — keep it short.
    return 'Search failed. Try again.';
}

function showStatus(el, kind, text) {
    if (!el) return;
    el.hidden = false;
    el.textContent = text;
    el.setAttribute('data-hof-ai-status-kind', kind);
}
