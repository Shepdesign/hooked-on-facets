// Page refresh after a filter change.
//
// Strategy: fetch the current URL again, parse the HTML, surgically swap
// the results container + each facet container. The QueryHook on the
// server side reads the same URL and filters accordingly, so the swapped
// HTML reflects the new state exactly.
//
// Focus preservation: if the user is actively interacting with a facet
// (mid-type in a search box, mid-edit in a range input), don't swap that
// facet's inputs — only update the count badges. Stops focus loss and
// stops debounced typing from being clobbered.

let inflight = null;

export async function refresh(targetUrl = window.location.href) {
    if (inflight) {
        // Coalesce rapid changes — the latest URL wins.
        inflight.target = targetUrl;
        return inflight.promise;
    }

    const controller = new AbortController();
    inflight = { target: targetUrl, controller, promise: null };

    inflight.promise = (async () => {
        try {
            announceRefresh('start');
            // Loop in case `target` was updated by a coalesced call.
            // eslint-disable-next-line no-constant-condition
            while (true) {
                const url = inflight.target;
                const html = await fetchHtml(url, controller.signal);
                if (inflight.target !== url) continue;

                applyHtml(html);
                announceRefresh('end');
                return;
            }
        } catch (err) {
            if (err?.name !== 'AbortError') {
                console.error('[HOF] refresh failed', err);
                announceRefresh('error', err);
            }
        } finally {
            inflight = null;
        }
    })();

    return inflight.promise;
}

async function fetchHtml(url, signal) {
    const res = await fetch(url, {
        credentials: 'same-origin',
        headers: { 'X-HOF-Refresh': '1' },
        signal,
    });
    if (!res.ok) throw new Error(`Refresh fetch ${res.status}`);
    return res.text();
}

function applyHtml(html) {
    const doc = new DOMParser().parseFromString(html, 'text/html');

    swapActiveFilters(doc);
    swapFacets(doc);
    swapResults(doc);
}

function swapActiveFilters(doc) {
    const incoming = doc.querySelector('[data-hof-active-filters]');
    const current = document.querySelector('[data-hof-active-filters]');
    if (!incoming || !current) return;

    // Replace innerHTML AND mirror the `hidden` attribute so the block
    // collapses when no filters are active.
    current.innerHTML = incoming.innerHTML;
    if (incoming.hasAttribute('hidden')) current.setAttribute('hidden', '');
    else                                  current.removeAttribute('hidden');
}

function swapFacets(doc) {
    const focused = document.activeElement;

    doc.querySelectorAll('[data-hof-facet]').forEach((incomingEl) => {
        const name = incomingEl.getAttribute('data-hof-facet');
        if (!name) return;

        const current = document.querySelector(`[data-hof-facet="${cssEscape(name)}"]`);
        if (!current) return;

        // Swatch tiles: term list is stable across filter states, so we
        // patch counts + weight + selected state on the existing tiles
        // rather than swapping innerHTML. Keeps the size-morph transition
        // running and avoids a flash of unstyled tiles.
        if (current.getAttribute('data-hof-display') === 'swatch') {
            patchSwatch(current, incomingEl);
            return;
        }

        // Swiper deck: never replace innerHTML — the user's skip-history
        // and deck position live only in the client DOM. Patch counts and
        // reconcile right-swipes from the URL state.
        if (current.getAttribute('data-hof-display') === 'swiper') {
            patchSwiper(current, incomingEl);
            return;
        }

        if (focused && current.contains(focused)) {
            // User is editing this facet — only update count badges so we don't
            // clobber the input value or steal focus.
            patchCounts(current, incomingEl);
            return;
        }

        current.innerHTML = incomingEl.innerHTML;
    });
}

function patchSwatch(current, incoming) {
    const incomingTiles = incoming.querySelectorAll('[data-hof-swatch-value]');
    incomingTiles.forEach((next) => {
        const value = next.getAttribute('data-hof-swatch-value');
        if (value === null) return;
        const tile = current.querySelector(
            `[data-hof-swatch-value="${cssEscape(value)}"]`
        );
        if (!tile) return;

        const weight = next.style.getPropertyValue('--hof-swatch-weight');
        if (weight) tile.style.setProperty('--hof-swatch-weight', weight);

        const countEl = tile.querySelector(`[data-hof-count="${cssEscape(value)}"]`);
        const incomingCount = next.querySelector(`[data-hof-count="${cssEscape(value)}"]`);
        if (countEl && incomingCount) {
            countEl.textContent = incomingCount.textContent;
        }

        const incomingChecked = next.querySelector('input[type="checkbox"]')?.checked ?? false;
        const tileChecked = tile.querySelector('input[type="checkbox"]');
        if (tileChecked && tileChecked.checked !== incomingChecked) {
            tileChecked.checked = incomingChecked;
        }
        if (incomingChecked) {
            tile.setAttribute('data-hof-selected', '1');
        } else {
            tile.removeAttribute('data-hof-selected');
        }
    });
}

function patchSwiper(current, incoming) {
    incoming.querySelectorAll('[data-hof-swiper-value]').forEach((next) => {
        const value = next.getAttribute('data-hof-swiper-value');
        if (value === null) return;
        const card = current.querySelector(
            `[data-hof-swiper-value="${cssEscape(value)}"]`
        );
        if (!card) return;

        // Counts
        const incomingCount = next.querySelector(`[data-hof-count="${cssEscape(value)}"]`);
        const currentCount = card.querySelector(`[data-hof-count="${cssEscape(value)}"]`);
        if (incomingCount && currentCount) {
            currentCount.textContent = incomingCount.textContent;
        }

        // Reconcile right-swiped (included) state from the URL. The server's
        // copy of the card has data-hof-swiped="right" + hidden iff the value
        // is in the URL filter; mirror that locally. Left-swipes (skips) live
        // only in the client and are preserved.
        const serverIncluded = next.getAttribute('data-hof-swiped') === 'right';
        const localSwipe     = card.getAttribute('data-hof-swiped');

        if (serverIncluded && localSwipe !== 'right') {
            card.setAttribute('data-hof-swiped', 'right');
            card.hidden = true;
            const cb = card.querySelector('input[type="checkbox"]');
            if (cb && !cb.checked) cb.checked = true;
        } else if (!serverIncluded && localSwipe === 'right') {
            // Server says not included but we have it as right-swiped — must
            // mean a popstate / external reset. Restore the card.
            card.removeAttribute('data-hof-swiped');
            card.hidden = false;
            card.style.removeProperty('--hof-swipe-x');
            const cb = card.querySelector('input[type="checkbox"]');
            if (cb && cb.checked) cb.checked = false;
        }
    });
}

function patchCounts(current, incoming) {
    const incomingCounts = incoming.querySelectorAll('[data-hof-count]');
    incomingCounts.forEach((incomingCount) => {
        const value = incomingCount.getAttribute('data-hof-count');
        if (value === null) return;
        const currentCount = current.querySelector(`[data-hof-count="${cssEscape(value)}"]`);
        if (currentCount) {
            currentCount.textContent = incomingCount.textContent;
        }
    });
}

function swapResults(doc) {
    const incoming = doc.querySelector('.hof-results');
    const current = document.querySelector('.hof-results');
    if (incoming && current) {
        current.innerHTML = incoming.innerHTML;
    }
}

function announceRefresh(phase, detail = null) {
    document.dispatchEvent(
        new CustomEvent('hof:refresh', { detail: { phase, error: detail } })
    );
}

// CSS.escape polyfill — IE/old browsers might not have it, but more
// importantly we still need it on values that contain quotes.
function cssEscape(value) {
    if (typeof CSS !== 'undefined' && typeof CSS.escape === 'function') {
        return CSS.escape(value);
    }
    return String(value).replace(/["\\]/g, '\\$&');
}
