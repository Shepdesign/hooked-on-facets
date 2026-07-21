import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { Store, buildUrl } from '../../public/src/state.js';

// Pretty config fixture: brand/color identity (taxonomy), material has a slug
// map (meta). "sku" is absent from facets → always query tail, like over-cap
// facets server-side.
const PRETTY = {
    base: 'filter',
    basePath: '/shop/',
    facets: ['brand', 'color', 'material'],
    slugMaps: { material: { 'Solid Oak': 'solid-oak' } },
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
        expect(url.searchParams.getAll('hof[brand][]')).toEqual(['nike']);
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
        expect(url.searchParams.getAll('hof[sku][]')).toEqual(['A100']);
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
});
