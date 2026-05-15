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

    swapFacets(doc);
    swapResults(doc);
}

function swapFacets(doc) {
    const focused = document.activeElement;

    doc.querySelectorAll('[data-hof-facet]').forEach((incomingEl) => {
        const name = incomingEl.getAttribute('data-hof-facet');
        if (!name) return;

        const current = document.querySelector(`[data-hof-facet="${cssEscape(name)}"]`);
        if (!current) return;

        if (focused && current.contains(focused)) {
            // User is editing this facet — only update count badges so we don't
            // clobber the input value or steal focus.
            patchCounts(current, incomingEl);
            return;
        }

        current.innerHTML = incomingEl.innerHTML;
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
