// Thin fetch wrapper around the HOF REST API.
// Reads window.hofAdmin set by the PHP bootstrap (see MenuRegistrar).

const bootstrap = typeof window !== 'undefined' ? window.hofAdmin || {} : {};

async function request(path, options = {}) {
    const base = bootstrap.restUrl || '/wp-json/hof/v1/';
    const url = base + String(path).replace(/^\//, '');

    const headers = {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        ...(options.headers || {}),
    };
    if (bootstrap.nonce) {
        headers['X-WP-Nonce'] = bootstrap.nonce;
    }

    const res = await fetch(url, { credentials: 'same-origin', ...options, headers });

    if (!res.ok) {
        let detail = '';
        try {
            const body = await res.json();
            detail = body?.message || JSON.stringify(body);
        } catch {
            detail = await res.text().catch(() => '');
        }
        throw new Error(`${res.status} ${res.statusText}${detail ? ` — ${detail}` : ''}`);
    }

    return res.json();
}

export const listFacets = () => request('facets');

export const saveFacets = (facets) =>
    request('facets', {
        method: 'PUT',
        body: JSON.stringify({ facets }),
    });

export const reindex = () => request('reindex', { method: 'POST' });

export const getReindexStatus = () => request('reindex/status');

export const applyFilter = (filters, page = 1, perPage = 20) =>
    request('filter', {
        method: 'POST',
        body: JSON.stringify({ filters, page, per_page: perPage }),
    });
