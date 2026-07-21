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

    appendHofParams(url, state);
    return url;
}

/** Split state into pretty path segments + query tail per the shared rules. */
function encodePretty(state, pretty) {
    const segments = [];
    const tail = {};
    const handled = new Set();

    for (const name of pretty.facets) {
        if (!(name in state)) continue;
        handled.add(name);
        const value = state[name];

        const isRange = value !== null && typeof value === 'object' && !Array.isArray(value);
        if (isRange) {
            tail[name] = value;
            continue;
        }

        const values = Array.isArray(value) ? value : [value];
        const map = pretty.slugMaps?.[name];
        const slugs = [];
        let bail = false;
        for (const v of values) {
            const slug = map ? map[String(v)] : String(v);
            if (slug == null) { bail = true; break; }
            slugs.push(slug);
        }
        if (bail) {
            tail[name] = value; // one unmappable value → whole facet to tail
            continue;
        }
        slugs.sort();
        for (const slug of slugs) {
            segments.push(encodeURIComponent(name), encodeURIComponent(slug));
        }
    }

    for (const [name, value] of Object.entries(state)) {
        if (!handled.has(name)) tail[name] = value;
    }

    return { segments, tail };
}

function joinPath(basePath, base, segments) {
    return `${basePath.replace(/\/$/, '')}/${base}/${segments.join('/')}/`;
}

function appendHofParams(url, state) {
    for (const [name, value] of Object.entries(state)) {
        if (Array.isArray(value)) {
            for (const v of value) url.searchParams.append(`hof[${name}][]`, String(v));
        } else if (typeof value === 'object' && value !== null) {
            for (const [k, v] of Object.entries(value)) {
                url.searchParams.set(`hof[${name}][${k}]`, String(v));
            }
        } else if (value !== '' && value != null) {
            url.searchParams.set(`hof[${name}]`, String(value));
        }
    }
}

function parseHofParams(url) {
    const out = {};
    for (const [key, value] of url.searchParams.entries()) {
        const match = key.match(/^hof\[([^\]]+)\](?:\[([^\]]*)\])?$/);
        if (!match) continue;
        const [, name, sub] = match;

        if (sub === undefined) {
            // hof[brand]=acme — scalar
            out[name] = value;
        } else if (sub === '') {
            // hof[brand][]=acme — array element
            if (!Array.isArray(out[name])) out[name] = [];
            out[name].push(value);
        } else {
            // hof[price][min]=10 — object key
            if (typeof out[name] !== 'object' || Array.isArray(out[name]) || out[name] === null) {
                out[name] = {};
            }
            out[name][sub] = value;
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
