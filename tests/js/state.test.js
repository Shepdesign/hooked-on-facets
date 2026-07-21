import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { Store, buildUrl } from '../../public/src/state.js';

// Pretty config fixture: brand/color are taxonomy (slug map is identity
// pairs, per SlugMapper::client_map() shipping a real map for every
// path-eligible facet — no client-side identity fallback), material has a
// meta slug map. "sku" is absent from facets → always query tail, like
// over-cap facets server-side. facetOrder lists EVERY configured facet
// (path-eligible or not — price/search are tail-only) in hof_facets saved
// order, which the tail-ordering pass needs to reproduce the server's
// canonical order (AssetLoader ships this alongside `facets`).
const PRETTY = {
    base: 'filter',
    basePath: '/shop/',
    facets: ['brand', 'color', 'material'],
    facetOrder: ['brand', 'color', 'material', 'price', 'search'],
    slugMaps: {
        brand: { nike: 'nike', adidas: 'adidas' },
        color: { blue: 'blue', red: 'red' },
        material: { 'Solid Oak': 'solid-oak' },
    },
};

describe('query mode (pretty off)', () => {
    beforeEach(() => {
        window.hofPublic = {};
    });

    it('round-trips hof params through buildUrl/hydrateFromUrl', () => {
        const url = buildUrl(
            { brand: ['nike'], price: { min: '10' } },
            'https://shop.test/shop/?utm=x'
        );
        expect(url.pathname).toBe('/shop/');
        expect(url.searchParams.get('utm')).toBe('x');
        // Numeric index, matching PHP's http_build_query() canonical form —
        // not the legacy hof[brand][]= append form.
        expect(url.searchParams.get('hof[brand][0]')).toBe('nike');
        expect(url.searchParams.get('hof[price][min]')).toBe('10');

        const store = new Store();
        store.hydrateFromUrl(url.toString());
        expect(store.get()).toEqual({ brand: ['nike'], price: { min: '10' } });
    });
});

describe('pretty mode', () => {
    beforeEach(() => {
        window.hofPublic = { prettyUrls: PRETTY };
    });
    afterEach(() => {
        window.hofPublic = {};
    });

    it('encodes discrete facets into the path, canonical order', () => {
        const url = buildUrl(
            { color: ['red', 'blue'], brand: ['nike'] },
            'https://shop.test/shop/'
        );
        expect(url.pathname).toBe('/shop/filter/brand/nike/color/blue/color/red/');
        expect([...url.searchParams.keys()]).toEqual([]);
    });

    it('uses slug maps for meta values and keeps ranges on the tail', () => {
        const url = buildUrl(
            { material: ['Solid Oak'], price: { min: '10' } },
            'https://shop.test/shop/'
        );
        expect(url.pathname).toBe('/shop/filter/material/solid-oak/');
        expect(url.searchParams.get('hof[price][min]')).toBe('10');
    });

    it('unknown facets fall back to the query tail', () => {
        const url = buildUrl({ sku: ['A100'] }, 'https://shop.test/shop/');
        expect(url.pathname).toBe('/shop/');
        expect(url.searchParams.get('hof[sku][0]')).toBe('A100');
    });

    it('resets to basePath when state is empty, preserving other params', () => {
        const url = buildUrl(
            {},
            'https://shop.test/shop/filter/brand/nike/?utm=x'
        );
        expect(url.pathname).toBe('/shop/');
        expect(url.searchParams.get('utm')).toBe('x');
    });

    it('drops the /page/N/ segment when filters change', () => {
        const url = buildUrl(
            { brand: ['nike'] },
            'https://shop.test/shop/page/3/'
        );
        expect(url.pathname).toBe('/shop/filter/brand/nike/');
    });

    it('hydrates state from a pretty path plus query tail', () => {
        const store = new Store();
        store.hydrateFromUrl(
            'https://shop.test/shop/filter/brand/nike/material/solid-oak/?hof[price][min]=10'
        );
        expect(store.get()).toEqual({
            brand: ['nike'],
            material: ['Solid Oak'],
            price: { min: '10' },
        });
    });

    it('pretty path wins over a stale query key', () => {
        const store = new Store();
        store.hydrateFromUrl(
            'https://shop.test/shop/filter/brand/nike/?hof[brand][]=stale'
        );
        expect(store.get()).toEqual({ brand: ['nike'] });
    });

    // ── Byte-for-byte server/client canonical equivalence ───────────────

    it('serializes an array-valued tail with numeric indices, matching the server canonical form', () => {
        const url = buildUrl(
            { brand: ['nike'], _bin_ids: ['12', '34'] },
            'https://shop.test/shop/'
        );
        expect(url.pathname).toBe('/shop/filter/brand/nike/');
        expect(url.searchParams.get('hof[_bin_ids][0]')).toBe('12');
        expect(url.searchParams.get('hof[_bin_ids][1]')).toBe('34');
    });

    it('hydrate folds a numeric-indexed tail group back into a real array', () => {
        const store = new Store();
        store.hydrateFromUrl(
            'https://shop.test/shop/?hof[_bin_ids][0]=12&hof[_bin_ids][1]=34'
        );
        const state = store.get();
        expect(Array.isArray(state._bin_ids)).toBe(true);
        expect(state._bin_ids).toEqual(['12', '34']);
    });

    it('orders tail params by configured facet order, then lexicographically', () => {
        const url = buildUrl(
            { search: 'oak', brand: ['nike'], price: { min: '10' } },
            'https://shop.test/shop/'
        );
        // facetOrder: brand, color, material, price, search — brand paths;
        // price and search are tail-only and land in that relative order.
        expect([...url.searchParams.keys()]).toEqual(['hof[price][min]', 'hof[search]']);
    });

    it('bails a whole path-eligible facet to the tail when a value is missing from its slug map', () => {
        // 'ghost' isn't in the brand map — no identity fallback, so the
        // entire brand facet (including 'nike', which IS mappable) bails to
        // the tail, mirroring UrlCodec::encode()'s all-or-nothing rule.
        const url = buildUrl(
            { brand: ['nike', 'ghost'] },
            'https://shop.test/shop/'
        );
        expect(url.pathname).toBe('/shop/');
        expect(url.searchParams.get('hof[brand][0]')).toBe('nike');
        expect(url.searchParams.get('hof[brand][1]')).toBe('ghost');
    });
});
