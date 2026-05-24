// Swipe-deck runtime — drag, buttons, keyboard.
//
// State model:
//   --hof-swipe-x  unitless pixels on the top card during a drag; the CSS
//                  consumes it via translateX/rotate, so we keep math here.
//   data-hof-swiped="right"|"left"  set when a card commits; CSS animates
//                  the card off-screen, then JS hides it after transitionend.
//   data-hof-swiper-pos  0…n on visible non-swiped cards; CSS scales and
//                  offsets behind cards by this value.
//
// Right-swipe checks the card's hidden checkbox and dispatches a `change`
// event, so the main.js delegated handler — same one checkbox/swatch use —
// picks up the selection. Nothing in the URL state pipeline knows about
// swipes; this module is purely UI sugar over the multi-select form.

const THRESHOLD = 80;          // px past start to count as a commit
const ROTATE_PER_PX = 1 / 20;  // 1deg per 20px of horizontal travel

export function initSwiper(root = document, { signal } = {}) {
    const opts = signal ? { signal } : undefined;
    root.querySelectorAll('[data-hof-display="swiper"]').forEach(updateDeckPositions);

    document.addEventListener('pointerdown', onPointerDown, opts);
    document.addEventListener('click', onClick, opts);
    document.addEventListener('keydown', onKeyDown, opts);
    // After refresh.js patches a swiper, fix up positions in case the
    // included-set changed underneath us.
    document.addEventListener('hof:refresh', (e) => {
        if (e.detail?.phase !== 'end') return;
        document.querySelectorAll('[data-hof-display="swiper"]').forEach(updateDeckPositions);
    }, opts);
}

function onPointerDown(e) {
    const card = e.target.closest('[data-hof-swiper-card]');
    if (!card) return;
    const facet = card.closest('[data-hof-facet]');
    if (!facet || facet.getAttribute('data-hof-display') !== 'swiper') return;
    if (card.hasAttribute('data-hof-swiped')) return;
    if (getTopCard(facet) !== card) return;

    // Ignore drags that start on the buttons or the checkbox itself.
    if (e.target.closest('input,button,a')) return;

    const startX = e.clientX;
    let dx = 0;
    card.setPointerCapture(e.pointerId);
    card.setAttribute('data-hof-dragging', '1');

    const onMove = (ev) => {
        if (ev.pointerId !== e.pointerId) return;
        dx = ev.clientX - startX;
        card.style.setProperty('--hof-swipe-x', String(dx));
    };
    const onUp = (ev) => {
        if (ev.pointerId !== e.pointerId) return;
        card.removeEventListener('pointermove', onMove);
        card.removeEventListener('pointerup', onUp);
        card.removeEventListener('pointercancel', onUp);
        card.removeAttribute('data-hof-dragging');

        if (dx > THRESHOLD)      commitSwipe(card, 'right');
        else if (dx < -THRESHOLD) commitSwipe(card, 'left');
        else card.style.removeProperty('--hof-swipe-x'); // snap back
    };

    card.addEventListener('pointermove', onMove);
    card.addEventListener('pointerup', onUp);
    card.addEventListener('pointercancel', onUp);
}

function commitSwipe(card, direction) {
    card.setAttribute('data-hof-swiped', direction);
    // Fly-off — CSS transitions transform via this var.
    card.style.setProperty('--hof-swipe-x', direction === 'right' ? '2000' : '-2000');

    if (direction === 'right') {
        const cb = card.querySelector('input[type="checkbox"]');
        if (cb && !cb.checked) {
            cb.checked = true;
            cb.dispatchEvent(new Event('change', { bubbles: true }));
        }
    }

    const finalize = () => {
        card.hidden = true;
        card.style.removeProperty('--hof-swipe-x');
        card.removeEventListener('transitionend', finalize);
        const facet = card.closest('[data-hof-facet]');
        if (facet) updateDeckPositions(facet);
    };
    card.addEventListener('transitionend', finalize);
    // Fallback if no transition fires (reduced-motion).
    setTimeout(finalize, 500);
}

function onClick(e) {
    const action = e.target.closest('[data-hof-swiper-action]');
    if (action) {
        const facet = action.closest('[data-hof-facet]');
        if (!facet) return;
        const top = getTopCard(facet);
        if (!top) return;
        const dir = action.getAttribute('data-hof-swiper-action') === 'include' ? 'right' : 'left';
        commitSwipe(top, dir);
        return;
    }

    const reset = e.target.closest('[data-hof-swiper-reset]');
    if (reset) {
        e.preventDefault();
        const facet = reset.closest('[data-hof-facet]');
        if (!facet) return;
        let changed = false;
        facet.querySelectorAll('[data-hof-swiper-card]').forEach((card) => {
            card.hidden = false;
            card.removeAttribute('data-hof-swiped');
            card.style.removeProperty('--hof-swipe-x');
            const cb = card.querySelector('input[type="checkbox"]');
            if (cb && cb.checked) {
                cb.checked = false;
                changed = true;
            }
        });
        if (changed) {
            // One change event drives a single refresh, instead of N.
            facet.querySelector('input[type="checkbox"]')
                ?.dispatchEvent(new Event('change', { bubbles: true }));
        }
        updateDeckPositions(facet);
    }
}

function onKeyDown(e) {
    if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') return;
    const facet = e.target.closest?.('[data-hof-display="swiper"]');
    if (!facet) return;
    const top = getTopCard(facet);
    if (!top) return;
    e.preventDefault();
    commitSwipe(top, e.key === 'ArrowRight' ? 'right' : 'left');
}

function getTopCard(facet) {
    // First visible, non-swiped card in DOM order is the topmost.
    return facet.querySelector(
        '[data-hof-swiper-card]:not([hidden]):not([data-hof-swiped])'
    );
}

function updateDeckPositions(facet) {
    // Blueprint sandbox: deck depth caps how many stacked cards are visible
    // in Card / Swipe mode. Grid variant ignores this — the grid shows all.
    const variant  = facet.getAttribute('data-hof-swiper-variant') || 'Card';
    const rawDepth = parseInt(facet.getAttribute('data-hof-swiper-depth') || '3', 10);
    const depth    = (variant === 'Grid' || !Number.isFinite(rawDepth) || rawDepth <= 0)
        ? Infinity
        : rawDepth;

    const remaining = facet.querySelectorAll(
        '[data-hof-swiper-card]:not([hidden]):not([data-hof-swiped])'
    );
    remaining.forEach((card, idx) => {
        card.style.setProperty('--hof-swiper-pos', String(idx));
        // Cards beyond the visible deck depth get pushed off-screen via
        // display:none rather than the `hidden` attribute — `hidden` would
        // exclude them from getTopCard() and the swipe progression.
        if (idx >= depth) card.style.display = 'none';
        else              card.style.display = '';
    });
    const done = facet.querySelector('[data-hof-swiper-done]');
    if (done) done.hidden = remaining.length > 0;
    facet.querySelectorAll('[data-hof-swiper-action]').forEach((btn) => {
        btn.disabled = remaining.length === 0;
    });
}
