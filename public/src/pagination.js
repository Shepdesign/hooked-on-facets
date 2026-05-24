// Pagination — click handler for the numbered nav rendered by
// Renderer::render_pagination().
//
// Server emits real <a href> links so the nav works without JS and SEO
// crawlers see real URLs. This module just upgrades clicks to a SPA-style
// pushState + refresh so the result region swaps in place instead of a
// full page reload.

import { refresh } from './refresh.js';

export function initPagination({ signal } = {}) {
    const opts = signal ? { signal } : undefined;
    document.addEventListener('click', onClick, opts);
}

function onClick(e) {
    // Only intercept primary-button, no-modifier clicks. Cmd/Ctrl-click,
    // middle-click, etc. fall through to the browser's native behavior so
    // "open in new tab" still works.
    if (e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;

    const link = e.target.closest('a[data-hof-page]');
    if (!link) return;

    const href = link.getAttribute('href');
    if (!href) return;

    e.preventDefault();
    window.history.pushState({}, '', href);
    refresh(href).then(() => {
        // After the new page renders, scroll the results region into view
        // so a paginated jump doesn't leave the user staring at the same
        // facet pane. Use the existing .hof-results region if present;
        // otherwise scroll to the new pagination nav itself.
        const target = document.querySelector('.hof-results') || link.closest('[data-hof-display="pagination"]');
        if (target) {
            target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    });
}
