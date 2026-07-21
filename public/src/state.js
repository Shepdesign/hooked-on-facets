// Filter state store + URL sync.
//
// Single source of truth for what's currently filtered. Reads from URL on
// boot and on popstate; writes back via pushState. Notifies subscribers on
// every change so the rest of the runtime can react (refresh, etc.).

export class Store {
    constructor() {
        /** @type {Record<string, any>} */
        this.state = {};
        /** @type {Set<(state: Record<string, any>) => void>} */
        this.subscribers = new Set();
    }

    /**
     * Parse the current URL — pretty /filter/ path (when configured) merged
     * with `?hof[*]` params; the path wins for any facet present in both.
     * Silent — no subscriber notification. Use after initial load or popstate.
     */
    hydrateFromUrl(url = window.location.href) {
        const parsed = new URL(url);
        const fromQuery = parseHofParams(parsed);
        const fromPath = parsePrettyPath(parsed, window.hofPublic?.prettyUrls);
        this.state = { ...fromQuery, ...fromPath };
    }

    /**
     * Mutate a facet's value. Empty / null / [] removes it.
     */
    set(name, value) {
        if (value == null) {
            delete this.state[name];
        } else if (Array.isArray(value)) {
            if (value.length === 0) delete this.state[name];
            else this.state[name] = value;
        } else if (typeof value === 'object') {
            const trimmed = {};
            for (const [k, v] of Object.entries(value)) {
                if (v != null && v !== '') trimmed[k] = v;
            }
            if (Object.keys(trimmed).length === 0) delete this.state[name];
            else this.state[name] = trimmed;
        } else if (value === '') {
            delete this.state[name];
        } else {
            this.state[name] = value;
        }
        this.notify();
    }

    clear() {
        this.state = {};
        this.notify();
    }

    get() {
        return this.state;
    }

    subscribe(fn) {
        this.subscribers.add(fn);
        return () => this.subscribers.delete(fn);
    }

    notify() {
        for (const fn of this.subscribers) fn(this.state);
    }
}

/**
 * Build a URL for the given state. In pretty mode, discrete facet values go
 * into the /filter/ path (canonical order: configured facet order, values
 * sorted by slug); everything else — ranges, search, reserved keys, facets
 * without a path mapping — stays on the ?hof[*] tail. Non-hof params are
 * preserved either way. Any /page/N/ segment is dropped: changing filters
 * returns to page 1.
 */
export function buildUrl(state, base = window.location.href) {
    const url = new URL(base);

    for (const key of [...url.searchParams.keys()]) {
        if (key.startsWith('hof[') || key === 'hof') {
            url.searchParams.delete(key);
        }
    }

    const pretty = window.hofPublic?.prettyUrls;
    if (pretty && pretty.base && pretty.basePath) {
        const { segments, tail } = encodePretty(state, pretty);
        url.pathname = segments.length
            ? joinPath(pretty.basePath, pretty.base, segments)
            : pretty.basePath;
        appendHofParams(url, tail);
        return url;
    }

    appendHofParams(url, Object.entries(state));
    return url;
}

/**
 * Split state into pretty path segments + an ordered query tail, mirroring
 * UrlCodec::encode() exactly:
 *   - a path-eligible facet ALWAYS resolves through its slug map — there is
 *     no identity fallback, even for taxonomy facets whose pairs happen to
 *     be identity (see SlugMapper::client_map()). A value missing from the
 *     map bails the WHOLE facet to the tail, same as the server.
 *   - the tail is canonically ordered: configured facets first in
 *     `pretty.facetOrder` (every configured facet, path-eligible or not —
 *     NOT `pretty.facets`, which only lists the path-eligible subset), then
 *     whatever's left sorted lexicographically by key.
 */
function encodePretty(state, pretty) {
    const segments = [];
    const tailMap = new Map();
    const handled = new Set();

    for (const name of pretty.facets) {
        if (!(name in state)) continue;
        handled.add(name);
        const value = state[name];

        const isRange = value !== null && typeof value === 'object' && !Array.isArray(value);
        if (isRange) {
            tailMap.set(name, value);
            continue;
        }

        const values = Array.isArray(value) ? value : [value];
        const map = pretty.slugMaps?.[name] || {};
        const slugs = [];
        let bail = false;
        for (const v of values) {
            const slug = map[String(v)];
            if (slug == null) { bail = true; break; }
            slugs.push(slug);
        }
        if (bail) {
            tailMap.set(name, value); // one unmappable value → whole facet to tail
            continue;
        }
        slugs.sort();
        for (const slug of slugs) {
            segments.push(encodeURIComponent(name), encodeURIComponent(slug));
        }
    }

    for (const [name, value] of Object.entries(state)) {
        if (!handled.has(name)) tailMap.set(name, value);
    }

    // Ordered array of [name, value] pairs, never a plain object — JS object
    // key iteration always visits integer-like keys in ascending numeric
    // order first, regardless of insertion order, which would silently
    // reorder a digit-named facet and break byte-for-byte parity with the
    // server's canonical tail string.
    const pairs = [];
    for (const name of pretty.facetOrder || pretty.facets || []) {
        if (tailMap.has(name)) {
            pairs.push([name, tailMap.get(name)]);
            tailMap.delete(name);
        }
    }
    for (const name of [...tailMap.keys()].sort()) {
        pairs.push([name, tailMap.get(name)]);
    }

    return { segments, tail: pairs };
}

function joinPath(basePath, base, segments) {
    return `${basePath.replace(/\/$/, '')}/${base}/${segments.join('/')}/`;
}

/**
 * @param {URL} url
 * @param {Array<[string, unknown]>} pairs Ordered [name, value] pairs — an
 *   object would risk JS's integer-key hoisting reordering a digit-named
 *   facet; see encodePretty()'s docblock.
 */
function appendHofParams(url, pairs) {
    for (const [name, value] of pairs) {
        if (Array.isArray(value)) {
            // Numeric indices, matching PHP's http_build_query() — the
            // server's canonical tail (SeoManager::pretty_url_for()) and any
            // 301 target are built the same way, so a `hof[name][]=` append
            // form here would produce a URL that never matches the server's
            // and would 301-loop or hydrate the array back as an object.
            value.forEach((v, i) => url.searchParams.set(`hof[${name}][${i}]`, String(v)));
        } else if (typeof value === 'object' && value !== null) {
            for (const [k, v] of Object.entries(value)) {
                url.searchParams.set(`hof[${name}][${k}]`, String(v));
            }
        } else if (value !== '' && value != null) {
            url.searchParams.set(`hof[${name}]`, String(value));
        }
    }
}

/**
 * Parse `?hof[*]` params back into state. Scalars and `hof[name][]=`-style
 * appends are handled inline; anything with a bracketed sub-key
 * (`hof[name][sub]=value`) is buffered per name and only resolved once every
 * key for that name has been seen, because the shape depends on the whole
 * group: `hof[price][min]=10&hof[price][max]=50` is an object, but
 * `hof[_bin_ids][0]=12&hof[_bin_ids][1]=34` — the server's canonical
 * array-tail wire shape, matching PHP's http_build_query() — is a real
 * Array. Folding that group into a plain object (as a naive per-key
 * assignment would) breaks every Array.isArray() check downstream (bin.js,
 * visual-dna.js) and produces a state that doesn't round-trip back through
 * buildUrl() to the same URL.
 */
function parseHofParams(url) {
    const out = {};
    const groups = {};

    for (const [key, value] of url.searchParams.entries()) {
        const match = key.match(/^hof\[([^\]]+)\](?:\[([^\]]*)\])?$/);
        if (!match) continue;
        const [, name, sub] = match;

        if (sub === undefined) {
            // hof[brand]=acme — scalar
            out[name] = value;
            continue;
        }
        if (!groups[name]) groups[name] = [];
        groups[name].push([sub, value]);
    }

    for (const [name, entries] of Object.entries(groups)) {
        if (entries.every(([k]) => k === '')) {
            // hof[brand][]=a&hof[brand][]=b — legacy append form.
            out[name] = entries.map(([, v]) => v);
        } else if (entries.every(([k]) => k !== '' && /^\d+$/.test(k))) {
            // hof[name][0]=a&hof[name][1]=b — numeric-indexed array, the
            // server's canonical wire shape. Order by index, not arrival.
            out[name] = entries
                .slice()
                .sort((a, b) => Number(a[0]) - Number(b[0]))
                .map(([, v]) => v);
        } else {
            // hof[price][min]=10 — a plain object.
            out[name] = {};
            for (const [k, v] of entries) {
                if (k !== '') out[name][k] = v;
            }
        }
    }

    return out;
}

/**
 * Decode /filter/name/slug/... path segments into state. Unknown facets or
 * slugs are skipped client-side (the server 404s truly invalid paths before
 * we ever hydrate). Meta slugs reverse through the localized slug map.
 */
function parsePrettyPath(url, pretty) {
    if (!pretty || !pretty.base) return {};
    const marker = `/${pretty.base}/`;
    const idx = url.pathname.indexOf(marker);
    if (idx === -1) return {};

    const inner = url.pathname
        .slice(idx + marker.length)
        .replace(/\/page\/\d+\/?$/, '')
        .replace(/\/$/, '');
    if (!inner) return {};

    const reverse = {};
    for (const [facet, map] of Object.entries(pretty.slugMaps || {})) {
        reverse[facet] = Object.fromEntries(
            Object.entries(map).map(([value, slug]) => [slug, value])
        );
    }

    const parts = inner.split('/').map(decodeURIComponent);
    const out = {};
    for (let i = 0; i + 1 < parts.length; i += 2) {
        const name = parts[i];
        const slug = parts[i + 1];
        if (!pretty.facets?.includes(name)) continue;
        const value = reverse[name] ? reverse[name][slug] : slug;
        if (value == null) continue;
        if (!Array.isArray(out[name])) out[name] = [];
        out[name].push(value);
    }
    return out;
}
