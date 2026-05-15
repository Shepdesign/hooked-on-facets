// REST helpers for the public runtime. Same shape as admin/api.js but reads
// window.hofPublic. Kept as a separate copy so the two bundles can ship
// independently — duplicating ~30 lines beats coupling the entries.

const bootstrap = typeof window !== 'undefined' ? window.hofPublic || {} : {};

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
        throw new Error(`${res.status} ${res.statusText}`);
    }
    return res.json();
}

export const applyFilter = (filters, page = 1, perPage = 20) =>
    request('filter', {
        method: 'POST',
        body: JSON.stringify({ filters, page, per_page: perPage }),
    });
