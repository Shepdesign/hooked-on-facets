// 2D slider — drag a rectangle on the plane to range-filter two underlying
// facets simultaneously. The facet itself produces no resolver filter; this
// runtime pushes {min, max} into the URL state of the x_facet and y_facet,
// which the existing range path handles natively.
//
// Gestures (iteration 2):
//
//   - pointerdown on empty plane → marquee drag, growing a rect under the cursor
//   - pointerdown on the existing rect → translate drag, sliding it
//   - pointerup → commit min/max for both axes to the store
//
// A tiny drag (< 6px) acts as a click and creates a small (≈12%-wide) rect
// centered on the click. Resize handles land in a follow-up iteration.
//
// Listeners attach to the facet WRAPPER (which persists across the swap that
// refresh.js does after each filter change). Plane and rect are looked up at
// event time so stale child references don't matter.

const MIN_DRAG_PX = 6;
const CLICK_RECT_PCT = 12;
// Minimum gap each axis must keep so a rect dragged in from one side never
// inverts past the opposite side. In percent of plane size.
const MIN_AXIS_PCT = 2;

export function init2dSlider(store) {
    const wired = new WeakSet();

    const wire = () => {
        document.querySelectorAll('.hof-facet-2d').forEach((facetEl) => {
            if (wired.has(facetEl)) return;
            wired.add(facetEl);
            attach(facetEl, store);
        });
    };

    wire();
    document.addEventListener('hof:refresh', (e) => {
        if (e.detail?.phase === 'end') wire();
    });
}

function attach(facetEl, store) {
    const xFacet = facetEl.getAttribute('data-hof-x-facet');
    const yFacet = facetEl.getAttribute('data-hof-y-facet');
    if (!xFacet || !yFacet) return;

    const xMin = parseFloat(facetEl.getAttribute('data-hof-x-min') || '0');
    const xMax = parseFloat(facetEl.getAttribute('data-hof-x-max') || '1');
    const yMin = parseFloat(facetEl.getAttribute('data-hof-y-min') || '0');
    const yMax = parseFloat(facetEl.getAttribute('data-hof-y-max') || '1');

    /** @type {null | { mode: 'marquee' | 'translate', planeBox: DOMRect, startX: number, startY: number, startRect?: {l:number,r:number,t:number,b:number}, plane: HTMLElement, rect: HTMLElement, pointerId: number }} */
    let drag = null;

    facetEl.addEventListener('pointerdown', (e) => {
        if (e.button !== 0) return;
        const plane = facetEl.querySelector('.hof-2d-plane');
        const rect  = facetEl.querySelector('.hof-2d-rect');
        if (!plane || !rect || !plane.contains(e.target)) return;
        e.preventDefault();
        try { facetEl.setPointerCapture(e.pointerId); } catch {}

        const startRect = readRectPct(rect);
        const fullBound = startRect.l + startRect.r < 0.5 && startRect.t + startRect.b < 0.5;
        const handle    = e.target.closest && e.target.closest('[data-hof-2d-handle]');

        let mode = 'marquee';
        let handleDir = null;
        if (handle && !fullBound) {
            // Resize: drag this handle's edge(s); pin the opposite edge(s).
            mode = 'resize';
            handleDir = handle.getAttribute('data-hof-2d-handle');
        } else if (!fullBound && (rect === e.target || rect.contains(e.target))) {
            mode = 'translate';
        }

        drag = {
            mode,
            handleDir,
            planeBox:  plane.getBoundingClientRect(),
            startX:    e.clientX,
            startY:    e.clientY,
            startRect: (mode === 'translate' || mode === 'resize') ? startRect : undefined,
            plane,
            rect,
            pointerId: e.pointerId,
        };

        rect.style.transition = 'none';
    });

    facetEl.addEventListener('pointermove', (e) => {
        if (!drag || e.pointerId !== drag.pointerId) return;
        applyDrag(drag, e);
    });

    const finishDrag = (e) => {
        if (!drag || e.pointerId !== drag.pointerId) return;
        const moved = Math.hypot(e.clientX - drag.startX, e.clientY - drag.startY);

        if (drag.mode === 'marquee' && moved < MIN_DRAG_PX) {
            const { planeBox, rect } = drag;
            const cxPct = clamp(((e.clientX - planeBox.left) / planeBox.width) * 100, 0, 100);
            const cyPct = clamp(((e.clientY - planeBox.top)  / planeBox.height) * 100, 0, 100);
            const half  = CLICK_RECT_PCT / 2;
            applyRectPct(rect, {
                l: clamp(cxPct - half, 0, 100 - CLICK_RECT_PCT),
                r: clamp(100 - cxPct - half, 0, 100 - CLICK_RECT_PCT),
                t: clamp(cyPct - half, 0, 100 - CLICK_RECT_PCT),
                b: clamp(100 - cyPct - half, 0, 100 - CLICK_RECT_PCT),
            });
        }

        drag.rect.style.removeProperty('transition');
        commit(drag.rect, store, xFacet, yFacet, xMin, xMax, yMin, yMax);
        try { facetEl.releasePointerCapture(drag.pointerId); } catch {}
        drag = null;
    };

    facetEl.addEventListener('pointerup',     finishDrag);
    facetEl.addEventListener('pointercancel', finishDrag);
}

function applyDrag(drag, e) {
    const { planeBox, startX, startY, rect } = drag;

    if (drag.mode === 'resize') {
        const dxPct = ((e.clientX - startX) / planeBox.width)  * 100;
        const dyPct = ((e.clientY - startY) / planeBox.height) * 100;
        const s = drag.startRect;
        const dir = drag.handleDir; // n/s/e/w/nw/ne/sw/se

        let nl = s.l, nr = s.r, nt = s.t, nb = s.b;
        if (dir.includes('w')) {
            // Left edge moves with pointer; right edge pinned.
            nl = clamp(s.l + dxPct, 0, 100 - s.r - MIN_AXIS_PCT);
        }
        if (dir.includes('e')) {
            // Right edge moves; left edge pinned.
            nr = clamp(s.r - dxPct, 0, 100 - s.l - MIN_AXIS_PCT);
        }
        if (dir.includes('n')) {
            // Top edge moves; bottom edge pinned.
            nt = clamp(s.t + dyPct, 0, 100 - s.b - MIN_AXIS_PCT);
        }
        if (dir.includes('s')) {
            // Bottom edge moves; top edge pinned.
            nb = clamp(s.b - dyPct, 0, 100 - s.t - MIN_AXIS_PCT);
        }
        applyRectPct(rect, { l: nl, r: nr, t: nt, b: nb });
        return;
    }

    if (drag.mode === 'marquee') {
        const sxPct = clamp(((startX - planeBox.left) / planeBox.width) * 100, 0, 100);
        const syPct = clamp(((startY - planeBox.top)  / planeBox.height) * 100, 0, 100);
        const exPct = clamp(((e.clientX - planeBox.left) / planeBox.width) * 100, 0, 100);
        const eyPct = clamp(((e.clientY - planeBox.top)  / planeBox.height) * 100, 0, 100);
        applyRectPct(rect, {
            l: Math.min(sxPct, exPct),
            r: 100 - Math.max(sxPct, exPct),
            t: Math.min(syPct, eyPct),
            b: 100 - Math.max(syPct, eyPct),
        });
        return;
    }

    // translate
    const dxPct = ((e.clientX - startX) / planeBox.width)  * 100;
    const dyPct = ((e.clientY - startY) / planeBox.height) * 100;
    const s = drag.startRect;
    const widthPct  = 100 - s.l - s.r;
    const heightPct = 100 - s.t - s.b;
    const nl = clamp(s.l + dxPct, 0, 100 - widthPct);
    const nt = clamp(s.t + dyPct, 0, 100 - heightPct);
    applyRectPct(rect, {
        l: nl,
        r: 100 - nl - widthPct,
        t: nt,
        b: 100 - nt - heightPct,
    });
}

function readRectPct(rect) {
    const s = rect.style;
    return {
        l: parsePct(s.left),
        r: parsePct(s.right),
        t: parsePct(s.top),
        b: parsePct(s.bottom),
    };
}

function applyRectPct(rect, p) {
    rect.style.left   = `${p.l.toFixed(2)}%`;
    rect.style.right  = `${p.r.toFixed(2)}%`;
    rect.style.top    = `${p.t.toFixed(2)}%`;
    rect.style.bottom = `${p.b.toFixed(2)}%`;
}

function commit(rect, store, xFacet, yFacet, xMin, xMax, yMin, yMax) {
    const p = readRectPct(rect);
    const xRange = xMax - xMin;
    const yRange = yMax - yMin;
    const xLow   = xMin + (p.l / 100) * xRange;
    const xHigh  = xMax - (p.r / 100) * xRange;
    // y inverted: top of plane = high value
    const yLow   = yMin + (p.b / 100) * yRange;
    const yHigh  = yMax - (p.t / 100) * yRange;

    const xFull = p.l < 0.5 && p.r < 0.5;
    const yFull = p.t < 0.5 && p.b < 0.5;
    store.set(xFacet, xFull ? null : { min: fmt(xLow), max: fmt(xHigh) });
    store.set(yFacet, yFull ? null : { min: fmt(yLow), max: fmt(yHigh) });
}

function fmt(n) {
    return Number(n.toFixed(2)).toString();
}

function clamp(v, lo, hi) {
    return Math.max(lo, Math.min(hi, v));
}

function parsePct(s) {
    if (!s) return 0;
    const m = /([0-9.]+)%/.exec(s);
    return m ? parseFloat(m[1]) : 0;
}
