import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';

// The public runtime (and the Pro renderers styled by this same sheet) use
// the HTML `hidden` attribute as the one visibility mechanism. In a real
// browser ANY author rule that sets `display` on such an element beats the
// UA's `[hidden] { display: none }` — the element renders even though the
// markup says it shouldn't. The only safe states are "no author display
// rule at all" or "a display: none !important guard also matches".
//
// jsdom can't reproduce the failure via getComputedStyle — it resolves
// [hidden] to display:none regardless of author rules — so the hidden-state
// assertions model the cascade directly from the parsed stylesheet: collect
// every author `display` declaration matching the hidden element and check
// a guard wins. The visible-state assertions use getComputedStyle, which
// jsdom does handle for non-hidden elements.

const cssPath = resolve(
    dirname(fileURLToPath(import.meta.url)),
    '../../public/src/styles/facets.css'
);
const facetsCss = readFileSync(cssPath, 'utf8');

// Every hidden-capable element, in the wrapper the renderers actually emit.
// Sources: src/Facets/Renderer.php (active filters) and the Pro add-on's
// ProRenderer (swiper, wheel, bin, ask, visual DNA) + public/src/*.js.
const FIXTURE = `
    <div class="hof-active-filters" data-hof-active-filters hidden></div>

    <div class="hof-facet hof-facet-swiper" data-hof-display="swiper">
        <div class="hof-swiper-deck">
            <div class="hof-swiper-card" data-hof-swiper-card data-hof-swiped="right" hidden></div>
            <p class="hof-swiper-done" data-hof-swiper-done hidden></p>
        </div>
    </div>

    <div class="hof-facet hof-facet-visual-dna" data-hof-display="visual_dna">
        <div class="hof-visual-dna-drop" data-hof-visual-drop>
            <input type="file" class="hof-visual-dna-file" data-hof-visual-file hidden>
        </div>
        <div class="hof-visual-dna-actions">
            <button type="button" class="hof-visual-dna-eyedrop" data-hof-visual-eyedrop hidden></button>
        </div>
        <div class="hof-visual-dna-result" data-hof-visual-result hidden>
            <div class="hof-visual-dna-palette" data-hof-visual-palette hidden></div>
        </div>
        <p class="hof-visual-dna-status" data-hof-visual-status hidden></p>
    </div>

    <div class="hof-facet hof-facet-ask" data-hof-display="ask">
        <div class="hof-ask-heard" data-hof-ask-heard hidden></div>
        <p class="hof-ask-status" data-hof-ask-status hidden></p>
    </div>

    <div class="hof-facet hof-facet-wheel" data-hof-display="wheel">
        <button type="button" class="hof-wheel-clear" data-hof-wheel-clear hidden></button>
    </div>

    <div class="hof-facet hof-facet-bin" data-hof-display="saved_bin">
        <p class="hof-bin-empty" data-hof-bin-empty hidden></p>
        <button type="button" class="hof-bin-clear" data-hof-bin-clear hidden></button>
    </div>
`;

const HIDDEN_SELECTORS = [
    '.hof-active-filters',
    '.hof-swiper-card',
    '.hof-swiper-done',
    '.hof-visual-dna-file',
    '.hof-visual-dna-eyedrop',
    '.hof-visual-dna-result',
    '.hof-visual-dna-palette',
    '.hof-visual-dna-status',
    '.hof-ask-heard',
    '.hof-ask-status',
    '.hof-wheel-clear',
    '.hof-bin-empty',
    '.hof-bin-clear',
];

let styleEl;

beforeEach(() => {
    styleEl = document.createElement('style');
    styleEl.textContent = facetsCss;
    document.head.appendChild(styleEl);
    document.body.innerHTML = FIXTURE;
});

afterEach(() => {
    styleEl.remove();
    document.body.innerHTML = '';
});

// Flatten the sheet (recursing into @media etc.) and pull every `display`
// declaration whose selector matches `el`. Selectors jsdom can't evaluate
// (pseudo-elements, :has in older engines) are skipped — none of them set
// display on the elements under test.
function displayDeclarationsFor(el, rules) {
    const found = [];
    for (const rule of rules) {
        if (rule.cssRules && rule.cssRules.length) {
            found.push(...displayDeclarationsFor(el, rule.cssRules));
            continue;
        }
        if (!rule.selectorText || !rule.style) continue;
        const value = rule.style.getPropertyValue('display');
        if (!value) continue;
        let matches = false;
        try {
            matches = el.matches(rule.selectorText);
        } catch {
            continue;
        }
        if (matches) {
            found.push({
                selector: rule.selectorText,
                value: value.trim(),
                important: rule.style.getPropertyPriority('display') === 'important',
            });
        }
    }
    return found;
}

describe('facets.css respects the hidden attribute', () => {
    it.each(HIDDEN_SELECTORS)('%s with [hidden] stays display: none', (selector) => {
        const el = document.querySelector(selector);
        expect(el, `fixture is missing ${selector}`).not.toBeNull();
        expect(el.hasAttribute('hidden')).toBe(true);

        const decls = displayDeclarationsFor(el, styleEl.sheet.cssRules);
        const importants = decls.filter((d) => d.important);

        // Hidden survives the cascade iff no author rule touches display,
        // or an !important none guard matches and nothing !important
        // overrides it.
        const hiddenWins =
            decls.length === 0 ||
            (importants.length > 0 && importants.every((d) => d.value === 'none'));

        expect(
            hiddenWins,
            `author display rules defeat [hidden] on ${selector}: ` + JSON.stringify(decls)
        ).toBe(true);
    });

    // Inverse: the guard must not clobber the visible state. These also prove
    // the stylesheet parsed and applies, so the checks above can't pass
    // vacuously.
    it.each([
        ['.hof-swiper-done', 'flex'],
        ['.hof-visual-dna-result', 'flex'],
        ['.hof-visual-dna-palette', 'flex'],
        ['.hof-visual-dna-eyedrop', 'inline-flex'],
        ['.hof-active-filters', 'flex'],
    ])('%s without [hidden] keeps display: %s', (selector, display) => {
        const el = document.querySelector(selector);
        el.removeAttribute('hidden');
        expect(getComputedStyle(el).display).toBe(display);
    });
});
