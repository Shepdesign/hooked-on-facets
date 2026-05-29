// Saved-bin runtime — a client-persisted comparison bin.
//
// Contents live in localStorage as [{id, label}] under the facet's
// data-hof-bin-key, so they survive navigation without a server round-trip.
// Shoppers add items with any element carrying data-hof-bin-add +
// data-hof-bin-id (+ optional data-hof-bin-label), or by dragging such an
// element onto the bin. When "show only saved" is on, the bin feeds its IDs
// to the resolver through the reserved _bin_ids filter key on the store.

let store = null;

export function initBin(s, root = document, { signal } = {}) {
    store = s;
    const opts = signal ? { signal } : undefined;

    renderAll(root);
    syncAddButtons(root);
    syncToggles(root);

    document.addEventListener('click', onClick, opts);
    document.addEventListener('change', onChange, opts);
    document.addEventListener('dragstart', onDragStart, opts);
    document.addEventListener('dragover', onDragOver, opts);
    document.addEventListener('drop', onDrop, opts);

    // After a refresh swaps markup (results + facets), re-render and re-sync.
    document.addEventListener('hof:refresh', (e) => {
        if (e.detail?.phase !== 'end') return;
        renderAll(document);
        syncAddButtons(document);
        syncToggles(document);
    }, opts);
}

// ── storage ────────────────────────────────────────────────────────────────

function read(key) {
    try {
        const raw = window.localStorage.getItem(key);
        const arr = raw ? JSON.parse(raw) : [];
        return Array.isArray(arr) ? arr.filter((i) => i && i.id != null) : [];
    } catch {
        return [];
    }
}

function write(key, items) {
    try {
        window.localStorage.setItem(key, JSON.stringify(items));
    } catch {
        /* private mode / quota — bin degrades to in-DOM only */
    }
}

function idsOf(items) {
    return items.map((i) => String(i.id));
}

// ── events ───────────────────────────────────────────────────────────────

function onClick(e) {
    const add = e.target.closest('[data-hof-bin-add]');
    if (add) {
        e.preventDefault();
        toggleItem(add.getAttribute('data-hof-bin-id'), add.getAttribute('data-hof-bin-label'));
        return;
    }
    const remove = e.target.closest('[data-hof-bin-remove]');
    if (remove) {
        e.preventDefault();
        removeItem(remove.getAttribute('data-hof-bin-remove'));
        return;
    }
    const clear = e.target.closest('[data-hof-bin-clear]');
    if (clear) {
        e.preventDefault();
        const bin = clear.closest('[data-hof-bin-key]');
        if (bin) setItems(bin, []);
    }
}

function onChange(e) {
    const toggle = e.target.closest?.('[data-hof-bin-toggle]');
    if (!toggle) return;
    const bin = toggle.closest('[data-hof-bin-key]');
    if (bin) applyShowOnly(bin, toggle.checked);
}

function onDragStart(e) {
    const add = e.target.closest?.('[data-hof-bin-add]');
    if (!add || !e.dataTransfer) return;
    const id = add.getAttribute('data-hof-bin-id') || '';
    const label = add.getAttribute('data-hof-bin-label') || '';
    e.dataTransfer.setData('text/plain', JSON.stringify({ id, label }));
    e.dataTransfer.effectAllowed = 'copy';
}

function onDragOver(e) {
    const zone = e.target.closest?.('[data-hof-bin-dropzone]');
    if (!zone) return;
    e.preventDefault(); // allow drop
    if (e.dataTransfer) e.dataTransfer.dropEffect = 'copy';
    zone.setAttribute('data-hof-bin-over', '1');
}

function onDrop(e) {
    const zone = e.target.closest?.('[data-hof-bin-dropzone]');
    if (!zone) return;
    e.preventDefault();
    zone.removeAttribute('data-hof-bin-over');
    let id = '';
    let label = '';
    try {
        const data = JSON.parse(e.dataTransfer?.getData('text/plain') || '{}');
        id = data.id || '';
        label = data.label || '';
    } catch {
        id = e.dataTransfer?.getData('text/plain') || '';
    }
    if (id) addItem(id, label, zone);
}

// ── mutations ──────────────────────────────────────────────────────────────

function eachBin(root, fn) {
    root.querySelectorAll('[data-hof-bin-key]').forEach(fn);
}

// Add/remove an id across every bin on the page (they share one storage key).
function toggleItem(id, label) {
    if (!id) return;
    const bin = document.querySelector('[data-hof-bin-key]');
    if (!bin) return;
    const key = bin.getAttribute('data-hof-bin-key');
    const items = read(key);
    const has = items.some((i) => String(i.id) === String(id));
    const next = has
        ? items.filter((i) => String(i.id) !== String(id))
        : [...items, { id: String(id), label: label || String(id) }];
    persist(key, next);
}

function addItem(id, label, bin) {
    const key = bin.getAttribute('data-hof-bin-key');
    const items = read(key);
    if (items.some((i) => String(i.id) === String(id))) return;
    persist(key, [...items, { id: String(id), label: label || String(id) }]);
}

function removeItem(id) {
    const bin = document.querySelector('[data-hof-bin-key]');
    if (!bin) return;
    const key = bin.getAttribute('data-hof-bin-key');
    persist(key, read(key).filter((i) => String(i.id) !== String(id)));
}

function setItems(bin, items) {
    persist(bin.getAttribute('data-hof-bin-key'), items);
}

// Write, re-render, re-sync add buttons, and (if any bin shows-only) push the
// new id set to the store so the result refreshes. The show-only bins are
// captured BEFORE re-render so an emptied bin still clears its _bin_ids
// (rendering must not be what decides whether the restriction reapplies).
function persist(key, items) {
    const activeBins = [];
    eachBin(document, (bin) => {
        if (bin.querySelector('[data-hof-bin-toggle]')?.checked) activeBins.push(bin);
    });
    write(key, items);
    renderAll(document);
    syncAddButtons(document);
    activeBins.forEach((bin) => applyShowOnly(bin, true));
}

function applyShowOnly(bin, on) {
    if (!store) return;
    const ids = idsOf(read(bin.getAttribute('data-hof-bin-key')));
    store.set('_bin_ids', on && ids.length ? ids : null);
}

// ── render ───────────────────────────────────────────────────────────────

function renderAll(root) {
    eachBin(root, renderBin);
}

function renderBin(bin) {
    const items = read(bin.getAttribute('data-hof-bin-key'));
    const list = bin.querySelector('[data-hof-bin-items]');
    if (list) {
        list.innerHTML = items.map((i) => `
            <li class="hof-bin-item">
                <span class="hof-bin-item-label">${escapeHtml(i.label || i.id)}</span>
                <button type="button" class="hof-bin-item-remove" data-hof-bin-remove="${escapeAttr(i.id)}"
                        aria-label="Remove">×</button>
            </li>`).join('');
    }
    const count = bin.querySelector('[data-hof-bin-count]');
    if (count) count.textContent = String(items.length);
    const empty = bin.querySelector('[data-hof-bin-empty]');
    if (empty) empty.hidden = items.length > 0;
    const clear = bin.querySelector('[data-hof-bin-clear]');
    if (clear) clear.hidden = items.length === 0;
}

// Reflect the active _bin_ids state onto the toggles (boot + popstate/refresh).
// Kept separate from renderBin so a user-driven persist doesn't fight it.
function syncToggles(root) {
    if (!store) return;
    const active = Array.isArray(store.get()._bin_ids) && store.get()._bin_ids.length > 0;
    eachBin(root, (bin) => {
        const toggle = bin.querySelector('[data-hof-bin-toggle]');
        if (!toggle) return;
        const has = read(bin.getAttribute('data-hof-bin-key')).length > 0;
        toggle.checked = active && has;
    });
}

// Mark add-buttons whose id is already in the bin, so themes can style them.
function syncAddButtons(root) {
    const bin = root.querySelector('[data-hof-bin-key]') || document.querySelector('[data-hof-bin-key]');
    if (!bin) return;
    const set = new Set(idsOf(read(bin.getAttribute('data-hof-bin-key'))));
    root.querySelectorAll('[data-hof-bin-add]').forEach((btn) => {
        const id = String(btn.getAttribute('data-hof-bin-id') || '');
        if (set.has(id)) btn.setAttribute('data-hof-bin-in', '1');
        else btn.removeAttribute('data-hof-bin-in');
    });
}

function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, (c) => (
        { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
    ));
}

function escapeAttr(s) {
    return escapeHtml(s);
}
