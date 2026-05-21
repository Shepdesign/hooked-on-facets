// Visual DNA — drop an image, paste a URL, or eyedrop any color, then snap
// the result to the nearest term in a target color facet and apply that
// term as a normal filter.
//
// Three input modalities collapse to the same flow:
//   image → dominantHex()  →  nearestTerm()  →  store.set(targetFacet, [slug])
//   url   → fetch+image    →  …
//   eyedrop → hex          →  nearestTerm()  →  …
//
// Listeners attach to the facet wrapper so they survive refresh.js's
// innerHTML swap.

export function initVisualDna(store) {
    const wired = new WeakSet();

    const wire = () => {
        document.querySelectorAll('.hof-facet-visual-dna').forEach((facetEl) => {
            if (wired.has(facetEl) || facetEl.classList.contains('hof-facet-misconfigured')) return;
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
    const drop      = facetEl.querySelector('[data-hof-visual-drop]');
    const fileInput = facetEl.querySelector('[data-hof-visual-file]');
    const urlInput  = facetEl.querySelector('[data-hof-visual-url]');
    const eyeBtn    = facetEl.querySelector('[data-hof-visual-eyedrop]');
    const clearBtn  = facetEl.querySelector('[data-hof-visual-clear]');

    // EyeDropper API is Chromium-only as of late 2025. Feature-detect.
    if (eyeBtn && 'EyeDropper' in window) {
        eyeBtn.hidden = false;
        eyeBtn.addEventListener('click', () => runEyedrop(facetEl, store));
    }

    // Drop-zone click = open file picker.
    drop?.addEventListener('click', (e) => {
        if (e.target === fileInput) return;
        fileInput?.click();
    });
    drop?.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            fileInput?.click();
        }
    });

    // Drag/drop on the drop zone.
    drop?.addEventListener('dragover', (e) => {
        e.preventDefault();
        drop.classList.add('is-dragover');
    });
    drop?.addEventListener('dragleave', () => drop.classList.remove('is-dragover'));
    drop?.addEventListener('drop', async (e) => {
        e.preventDefault();
        drop.classList.remove('is-dragover');
        const file = e.dataTransfer?.files?.[0];
        if (file && file.type.startsWith('image/')) {
            await runFile(facetEl, store, file);
        }
    });

    fileInput?.addEventListener('change', async () => {
        const file = fileInput.files?.[0];
        if (file) {
            await runFile(facetEl, store, file);
            fileInput.value = '';
        }
    });

    // URL paste + submit on Enter or blur.
    urlInput?.addEventListener('keydown', async (e) => {
        if (e.key !== 'Enter') return;
        e.preventDefault();
        const url = urlInput.value.trim();
        if (url) await runUrl(facetEl, store, url);
    });

    clearBtn?.addEventListener('click', () => clearResult(facetEl, store));
}

async function runFile(facetEl, store, file) {
    showStatus(facetEl, 'loading', 'Sampling…');
    try {
        const url = URL.createObjectURL(file);
        const img = await loadImage(url);
        URL.revokeObjectURL(url);
        const hex = dominantHex(img);
        commitColor(facetEl, store, hex);
    } catch (err) {
        showStatus(facetEl, 'error', "Couldn't read that image.");
    }
}

async function runUrl(facetEl, store, url) {
    showStatus(facetEl, 'loading', 'Sampling…');
    try {
        // `crossOrigin=anonymous` is required for getImageData. Hosts that
        // don't return permissive CORS headers will fail here — that's a
        // browser-level constraint we can't work around in pure JS.
        const img = await loadImage(url, true);
        const hex = dominantHex(img);
        commitColor(facetEl, store, hex);
    } catch (err) {
        showStatus(facetEl, 'error',
            "Couldn't sample that URL. The image host may block cross-origin reads — try downloading and dropping the file instead.");
    }
}

async function runEyedrop(facetEl, store) {
    showStatus(facetEl, 'loading', 'Pick a pixel anywhere on the page…');
    try {
        const ed = new window.EyeDropper();
        const result = await ed.open();
        commitColor(facetEl, store, normalizeHex(result.sRGBHex));
    } catch (err) {
        // User cancelled — silent.
        hideStatus(facetEl);
    }
}

function commitColor(facetEl, store, hex) {
    const map         = readColorMap(facetEl);
    const targetFacet = facetEl.getAttribute('data-hof-target-facet') || '';
    if (!map.length) {
        showStatus(facetEl, 'error',
            'No color terms available — the target facet has no matchable colors.');
        return;
    }
    const nearest = nearestTerm(hex, map);
    if (!nearest) {
        showStatus(facetEl, 'error', "Couldn't match that color to any term.");
        return;
    }
    renderResult(facetEl, hex, nearest);
    hideStatus(facetEl);
    if (targetFacet) store.set(targetFacet, [nearest.slug]);
}

function clearResult(facetEl, store) {
    const targetFacet = facetEl.getAttribute('data-hof-target-facet') || '';
    const result      = facetEl.querySelector('[data-hof-visual-result]');
    const url         = facetEl.querySelector('[data-hof-visual-url]');
    if (result) result.hidden = true;
    if (url) url.value = '';
    hideStatus(facetEl);
    if (targetFacet) store.set(targetFacet, []);
}

// ── DOM helpers ────────────────────────────────────────────────────────────

function readColorMap(facetEl) {
    const raw = facetEl.getAttribute('data-hof-color-map') || '[]';
    try {
        const parsed = JSON.parse(raw);
        return Array.isArray(parsed) ? parsed : [];
    } catch {
        return [];
    }
}

function renderResult(facetEl, hex, nearest) {
    const result   = facetEl.querySelector('[data-hof-visual-result]');
    const swatchEl = facetEl.querySelector('[data-hof-visual-swatch]');
    const hexEl    = facetEl.querySelector('[data-hof-visual-hex]');
    const matchEl  = facetEl.querySelector('[data-hof-visual-match]');
    if (!result) return;
    if (swatchEl) swatchEl.style.background = hex;
    if (hexEl)   hexEl.textContent = hex;
    if (matchEl) {
        matchEl.innerHTML = '';
        const dot = document.createElement('span');
        dot.className = 'hof-visual-dna-match-dot';
        dot.style.background = nearest.hex;
        matchEl.appendChild(dot);
        const lbl = document.createElement('span');
        lbl.textContent = nearest.label;
        matchEl.appendChild(lbl);
    }
    result.hidden = false;
}

function showStatus(facetEl, kind, text) {
    const el = facetEl.querySelector('[data-hof-visual-status]');
    if (!el) return;
    el.hidden = false;
    el.textContent = text;
    el.setAttribute('data-hof-visual-status-kind', kind);
}

function hideStatus(facetEl) {
    const el = facetEl.querySelector('[data-hof-visual-status]');
    if (!el) return;
    el.hidden = true;
    el.textContent = '';
    el.removeAttribute('data-hof-visual-status-kind');
}

// ── Image / canvas / dominant color ────────────────────────────────────────

function loadImage(src, crossOrigin = false) {
    return new Promise((resolve, reject) => {
        const img = new Image();
        if (crossOrigin) img.crossOrigin = 'anonymous';
        img.onload  = () => resolve(img);
        img.onerror = (e) => reject(e);
        img.src = src;
    });
}

// Quantize each channel to 4 bits → 4096 buckets, then pick the heaviest
// bucket that isn't near-white or near-black (unless those dominate).
// Cheap, deterministic, robust to JPEG noise. The 96×96 downsample keeps
// the loop tight regardless of source resolution.
function dominantHex(img) {
    const W = 96, H = 96;
    const canvas = document.createElement('canvas');
    canvas.width = W; canvas.height = H;
    const ctx = canvas.getContext('2d', { willReadFrequently: true });
    ctx.drawImage(img, 0, 0, W, H);
    const data = ctx.getImageData(0, 0, W, H).data;

    const buckets = new Map(); // key → {count, r, g, b}
    for (let i = 0; i < data.length; i += 4) {
        const a = data[i + 3];
        if (a < 200) continue; // skip transparent
        const r = data[i], g = data[i + 1], b = data[i + 2];
        const key = (r >> 4) << 8 | (g >> 4) << 4 | (b >> 4);
        const ex  = buckets.get(key);
        if (ex) { ex.count++; ex.r += r; ex.g += g; ex.b += b; }
        else    { buckets.set(key, { count: 1, r, g, b }); }
    }
    if (buckets.size === 0) return '#888888';

    // Pick the largest non-extreme bucket if extremes are a small minority.
    const sorted = Array.from(buckets.values()).sort((a, b) => b.count - a.count);
    const total  = sorted.reduce((s, b) => s + b.count, 0);
    for (const b of sorted) {
        const avgR = b.r / b.count, avgG = b.g / b.count, avgB = b.b / b.count;
        const isNearWhite = avgR > 245 && avgG > 245 && avgB > 245;
        const isNearBlack = avgR <  10 && avgG <  10 && avgB <  10;
        const isMajority  = b.count / total > 0.6;
        if (!isNearWhite && !isNearBlack) return rgbToHex(avgR, avgG, avgB);
        if (isMajority) return rgbToHex(avgR, avgG, avgB);
    }
    const top = sorted[0];
    return rgbToHex(top.r / top.count, top.g / top.count, top.b / top.count);
}

function rgbToHex(r, g, b) {
    const c = (v) => Math.max(0, Math.min(255, Math.round(v))).toString(16).padStart(2, '0');
    return `#${c(r)}${c(g)}${c(b)}`;
}

function normalizeHex(hex) {
    // EyeDropper returns lowercase already, but be safe.
    return /^#[0-9a-f]{6}$/i.test(hex) ? hex.toLowerCase() : '#888888';
}

// ── Color matching: nearest term in CIE LAB ΔE ─────────────────────────────

function nearestTerm(hex, map) {
    const target = hexToLab(hex);
    let best = null, bestDelta = Infinity;
    for (const term of map) {
        const lab = hexToLab(term.hex);
        const dE  = deltaE(target, lab);
        if (dE < bestDelta) { bestDelta = dE; best = term; }
    }
    return best;
}

function hexToLab(hex) {
    const r = parseInt(hex.substring(1, 3), 16) / 255;
    const g = parseInt(hex.substring(3, 5), 16) / 255;
    const b = parseInt(hex.substring(5, 7), 16) / 255;

    // sRGB → linear
    const lin = (c) => c <= 0.04045 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
    const R = lin(r), G = lin(g), B = lin(b);

    // linear RGB → XYZ (D65)
    const X = R * 0.4124564 + G * 0.3575761 + B * 0.1804375;
    const Y = R * 0.2126729 + G * 0.7151522 + B * 0.0721750;
    const Z = R * 0.0193339 + G * 0.1191920 + B * 0.9503041;

    // XYZ → LAB (D65 reference white)
    const Xn = 0.95047, Yn = 1.00000, Zn = 1.08883;
    const f = (t) => t > 0.008856 ? Math.cbrt(t) : (7.787 * t + 16 / 116);
    const fx = f(X / Xn), fy = f(Y / Yn), fz = f(Z / Zn);
    return {
        L: 116 * fy - 16,
        a: 500 * (fx - fy),
        b: 200 * (fy - fz),
    };
}

// Simple ΔE76 — adequate for "which term is closest" with the modest
// number of color terms typical Woo stores carry (<30). ΔE2000 is more
// accurate but the implementation is a small library on its own.
function deltaE(a, b) {
    const dL = a.L - b.L, da = a.a - b.a, dB = a.b - b.b;
    return Math.sqrt(dL * dL + da * da + dB * dB);
}
