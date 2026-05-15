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
     * Parse `?hof[brand][]=acme&hof[price][min]=10` into a normalized state.
     * Silent — no subscriber notification. Use after initial load or popstate.
     */
    hydrateFromUrl(url = window.location.href) {
        const parsed = parseHofParams(new URL(url));
        this.state = parsed;
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
 * Build a URL with the current state encoded as `?hof[*]` params.
 * Preserves all non-hof params already present.
 */
export function buildUrl(state, base = window.location.href) {
    const url = new URL(base);

    // Strip existing hof[*] params.
    for (const key of [...url.searchParams.keys()]) {
        if (key.startsWith('hof[') || key === 'hof') {
            url.searchParams.delete(key);
        }
    }

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

    return url;
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
