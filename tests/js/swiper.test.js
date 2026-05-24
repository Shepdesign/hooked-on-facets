import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

// initSwiper attaches listeners to `document`. Use vi.resetModules() so each
// test starts with a pristine module and avoids cross-test listener leaks.
async function loadFreshSwiper() {
    vi.resetModules();
    return import('../../public/src/swiper.js');
}

function buildDeck({ values = ['red', 'blue', 'green'], variant = 'Card', depth = 3, included = [] } = {}) {
    const includedSet = new Set(included);
    const cards = values.map((value) => {
        const swiped = includedSet.has(value) ? 'data-hof-swiped="right" hidden' : '';
        const checked = includedSet.has(value) ? 'checked' : '';
        return `
            <div class="hof-swiper-card" data-hof-swiper-card data-hof-swiper-value="${value}" ${swiped}>
                <input type="checkbox" name="hof[color][]" value="${value}" ${checked}>
                <span class="hof-facet-count" data-hof-count="${value}">12</span>
            </div>
        `;
    }).join('');

    return `
        <div class="hof-facet hof-facet-swiper"
             data-hof-facet="color"
             data-hof-display="swiper"
             data-hof-swiper-variant="${variant}"
             data-hof-swiper-depth="${depth}">
            <div class="hof-swiper-deck">
                ${cards}
                <p class="hof-swiper-done" data-hof-swiper-done hidden></p>
            </div>
            <div class="hof-swiper-controls">
                <button type="button" data-hof-swiper-action="skip"></button>
                <button type="button" data-hof-swiper-action="include"></button>
                <button type="button" data-hof-swiper-reset></button>
            </div>
        </div>
    `;
}

function getFacet() {
    return document.querySelector('[data-hof-facet="color"]');
}

function getCards() {
    return Array.from(document.querySelectorAll('[data-hof-swiper-card]'));
}

function getCard(value) {
    return document.querySelector(`[data-hof-swiper-value="${value}"]`);
}

// Drive commitSwipe to its terminal state by firing the transitionend the
// CSS would normally produce — the swiper module hides the card and shifts
// deck positions only after that event.
function flushTransition(card) {
    card.dispatchEvent(new Event('transitionend'));
}

let controller;

beforeEach(() => {
    document.body.innerHTML = '';
    controller = new AbortController();
});

afterEach(() => {
    // Detach the document-level listeners initSwiper registered, so the next
    // test starts with a clean global event surface.
    controller.abort();
    document.body.innerHTML = '';
});

describe('updateDeckPositions (via initSwiper)', () => {
    it('assigns --hof-swiper-pos 0..n on visible non-swiped cards', async () => {
        document.body.innerHTML = buildDeck();
        const { initSwiper } = await loadFreshSwiper();
        initSwiper(document.body, { signal: controller.signal });

        const cards = getCards();
        expect(cards[0].style.getPropertyValue('--hof-swiper-pos')).toBe('0');
        expect(cards[1].style.getPropertyValue('--hof-swiper-pos')).toBe('1');
        expect(cards[2].style.getPropertyValue('--hof-swiper-pos')).toBe('2');
    });

    it('Card variant honors deckDepth — cards past depth are display:none', async () => {
        document.body.innerHTML = buildDeck({ depth: 2 });
        const { initSwiper } = await loadFreshSwiper();
        initSwiper(document.body, { signal: controller.signal });

        const cards = getCards();
        expect(cards[0].style.display).toBe('');
        expect(cards[1].style.display).toBe('');
        expect(cards[2].style.display).toBe('none');
    });

    it('Grid variant ignores deckDepth — all cards remain visible', async () => {
        document.body.innerHTML = buildDeck({ variant: 'Grid', depth: 1 });
        const { initSwiper } = await loadFreshSwiper();
        initSwiper(document.body, { signal: controller.signal });

        const cards = getCards();
        expect(cards.every((c) => c.style.display !== 'none')).toBe(true);
    });
});

describe('click actions', () => {
    it('include → checks the top card, dispatches change, commits right', async () => {
        document.body.innerHTML = buildDeck();
        const { initSwiper } = await loadFreshSwiper();
        initSwiper(document.body, { signal: controller.signal });

        const onChange = vi.fn();
        document.addEventListener('change', onChange);

        document.querySelector('[data-hof-swiper-action="include"]').click();

        const top = getCard('red');
        expect(top.getAttribute('data-hof-swiped')).toBe('right');
        expect(top.querySelector('input').checked).toBe(true);
        expect(onChange).toHaveBeenCalled();
    });

    it('skip → commits left without checking the card', async () => {
        document.body.innerHTML = buildDeck();
        const { initSwiper } = await loadFreshSwiper();
        initSwiper(document.body, { signal: controller.signal });

        const onChange = vi.fn();
        document.addEventListener('change', onChange);

        document.querySelector('[data-hof-swiper-action="skip"]').click();

        const top = getCard('red');
        expect(top.getAttribute('data-hof-swiped')).toBe('left');
        expect(top.querySelector('input').checked).toBe(false);
        expect(onChange).not.toHaveBeenCalled();
    });

    it('after transitionend, the swiped card is hidden and the next card becomes top', async () => {
        document.body.innerHTML = buildDeck();
        const { initSwiper } = await loadFreshSwiper();
        initSwiper(document.body, { signal: controller.signal });

        document.querySelector('[data-hof-swiper-action="include"]').click();
        flushTransition(getCard('red'));

        expect(getCard('red').hidden).toBe(true);
        // The blue card should now be at position 0 of the visible deck.
        expect(getCard('blue').style.getPropertyValue('--hof-swiper-pos')).toBe('0');
    });
});

describe('keyboard', () => {
    it('ArrowRight inside the facet commits include on the top card', async () => {
        document.body.innerHTML = buildDeck();
        const { initSwiper } = await loadFreshSwiper();
        initSwiper(document.body, { signal: controller.signal });

        const top = getCard('red');
        top.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true }));

        expect(top.getAttribute('data-hof-swiped')).toBe('right');
        expect(top.querySelector('input').checked).toBe(true);
    });

    it('ArrowLeft inside the facet commits skip on the top card', async () => {
        document.body.innerHTML = buildDeck();
        const { initSwiper } = await loadFreshSwiper();
        initSwiper(document.body, { signal: controller.signal });

        const top = getCard('red');
        top.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowLeft', bubbles: true }));

        expect(top.getAttribute('data-hof-swiped')).toBe('left');
        expect(top.querySelector('input').checked).toBe(false);
    });

    it('arrow keys outside any swiper facet are ignored', async () => {
        document.body.innerHTML = `<div id="elsewhere" tabindex="0"></div>` + buildDeck();
        const { initSwiper } = await loadFreshSwiper();
        initSwiper(document.body, { signal: controller.signal });

        document.getElementById('elsewhere').dispatchEvent(
            new KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true })
        );

        expect(getCard('red').getAttribute('data-hof-swiped')).toBeNull();
    });
});

describe('done state', () => {
    it('reveals the done element and disables action buttons when all cards are swiped', async () => {
        document.body.innerHTML = buildDeck({ values: ['only'] });
        const { initSwiper } = await loadFreshSwiper();
        initSwiper(document.body, { signal: controller.signal });

        document.querySelector('[data-hof-swiper-action="include"]').click();
        flushTransition(getCard('only'));

        expect(getFacet().querySelector('[data-hof-swiper-done]').hidden).toBe(false);
        expect(document.querySelector('[data-hof-swiper-action="include"]').disabled).toBe(true);
        expect(document.querySelector('[data-hof-swiper-action="skip"]').disabled).toBe(true);
    });
});

describe('reset', () => {
    it('restores every card, clears check state, and fires one change event', async () => {
        document.body.innerHTML = buildDeck({ values: ['red', 'blue'] });
        const { initSwiper } = await loadFreshSwiper();
        initSwiper(document.body, { signal: controller.signal });

        // Burn through both cards: include red, skip blue.
        document.querySelector('[data-hof-swiper-action="include"]').click();
        flushTransition(getCard('red'));
        document.querySelector('[data-hof-swiper-action="skip"]').click();
        flushTransition(getCard('blue'));

        // Sanity: red was checked by the include action.
        expect(getCard('red').querySelector('input').checked).toBe(true);

        const onChange = vi.fn();
        document.addEventListener('change', onChange);

        document.querySelector('[data-hof-swiper-reset]').click();

        for (const card of getCards()) {
            expect(card.hidden).toBe(false);
            expect(card.getAttribute('data-hof-swiped')).toBeNull();
        }
        expect(getCard('red').querySelector('input').checked).toBe(false);
        // One coalesced change, not one per restored card.
        expect(onChange).toHaveBeenCalledTimes(1);
    });
});
