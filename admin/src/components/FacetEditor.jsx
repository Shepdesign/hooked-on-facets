import { validateFacet } from '../validation.js';

const KINDS = [
    { value: 'taxonomy', label: 'Taxonomy',           hint: 'e.g. product_cat, category, product_tag' },
    { value: 'meta',     label: 'Post meta',          hint: 'e.g. _price, _stock_status' },
    { value: 'field',    label: 'Post field',         hint: 'e.g. post_title, post_author' },
    { value: 'view',     label: 'Display-only (no filter)', hint: "Doesn't filter on its own — drives other facets via the URL state." },
];

// Display picker — grouped by what the shopper actually does with it,
// so a non-developer admin can scan the list and pick the right one.
// Internal slugs (visual_dna, ask) stay for stored-config back-compat;
// only the UI labels change.
const DISPLAY_GROUPS = [
    {
        group: 'Select',
        hint: 'Pick one or more options.',
        items: [
            { value: 'checkbox',  label: 'Checkbox list' },
            { value: 'radio',     label: 'Radio (single-select)' },
            { value: 'dropdown',  label: 'Dropdown' },
            { value: 'hierarchy', label: 'Hierarchy (nested taxonomy)' },
        ],
    },
    {
        group: 'Range',
        hint: 'Pick between two values.',
        items: [
            { value: 'range',      label: 'Range slider' },
            { value: 'date_range', label: 'Date range' },
        ],
    },
    {
        group: 'Boolean',
        hint: 'On or off.',
        items: [
            { value: 'toggle', label: 'Toggle' },
        ],
    },
    {
        group: 'Text',
        hint: 'Type to filter.',
        items: [
            { value: 'search', label: 'Search box' },
        ],
    },
    {
        group: 'Visual',
        hint: 'Swipeable, tappable, or color-driven.',
        items: [
            { value: 'swatch', label: 'Fluid swatches' },
            { value: 'swiper', label: 'Swipe deck' },
            { value: 'spin_the_wheel', label: 'Spin the wheel' },
        ],
    },
    {
        group: 'Navigation',
        hint: "Doesn't filter — moves shoppers through pages of results.",
        items: [
            { value: 'pagination', label: 'Pagination (numbered)' },
        ],
    },
    {
        group: 'Display-only (no filter)',
        hint: "Doesn't filter on its own — sends shoppers' input to other facets.",
        items: [
            { value: 'ask',        label: 'Natural-language search' },
            { value: 'visual_dna', label: 'Color match (drop an image)' },
        ],
    },
];

// Flat lookup used by the rest of the file — switching/validation
// shouldn't care which group a display lives in.
const DISPLAYS = DISPLAY_GROUPS.flatMap((g) => g.items);

// Displays that don't have a source — they orchestrate other facets
// (ask, visual_dna) or render result-region navigation (pagination).
const VIEW_DISPLAYS = new Set(['ask', 'visual_dna', 'pagination']);

// Displays that visual_dna can target (color-bearing displays).
const COLOR_TARGET_DISPLAYS = new Set(['checkbox', 'radio', 'dropdown', 'swatch', 'swiper']);

// Multi-value displays — a shopper can pick more than one value, so the
// any/all (OR/AND) match mode is meaningful.
const MULTI_VALUE_DISPLAYS = new Set(['checkbox', 'swatch', 'swiper']);

const sanitizeSlug = (raw) =>
    String(raw || '').toLowerCase().replace(/[^a-z0-9_-]/g, '');

export default function FacetEditor({ facet, onChange, onDelete, allFacets = [] }) {
    const kindDef = KINDS.find((k) => k.value === facet.kind) || KINDS[0];
    const isView  = VIEW_DISPLAYS.has(facet.display);
    const colorTargetFacets = allFacets.filter((f) =>
        COLOR_TARGET_DISPLAYS.has(f.display) && f.name !== facet.name
    );
    const settings = (facet.settings && typeof facet.settings === 'object' && !Array.isArray(facet.settings))
        ? facet.settings
        : {};

    const updateSettings = (patch) => {
        onChange({ settings: { ...settings, ...patch } });
    };

    // Live validation. Keys: name / label / kind / source / settings.<key>
    const issues = validateFacet(facet, allFacets);

    return (
        <div className="hof-editor">
            <div className="hof-editor-grid">
                <section className="hof-editor-form">
                    <h2 className="hof-editor-title">Edit facet</h2>

                    <label className={`hof-field ${issues.name ? 'is-invalid' : ''}`}>
                        <span className="hof-field-label">Slug</span>
                        <input
                            className="hof-input"
                            type="text"
                            value={facet.name}
                            onChange={(e) => onChange({ name: sanitizeSlug(e.target.value) })}
                            placeholder="brand"
                            aria-invalid={issues.name ? 'true' : 'false'}
                        />
                        {issues.name ? (
                            <span className="hof-field-error">{issues.name}</span>
                        ) : (
                            <span className="hof-field-help">
                                URL-safe identifier used in <code>?hof[slug]=…</code>. Lowercase, hyphens and underscores only.
                            </span>
                        )}
                    </label>

                    <label className={`hof-field ${issues.label ? 'is-invalid' : ''}`}>
                        <span className="hof-field-label">Label</span>
                        <input
                            className="hof-input"
                            type="text"
                            value={facet.label}
                            onChange={(e) => onChange({ label: e.target.value })}
                            placeholder="Brand"
                            aria-invalid={issues.label ? 'true' : 'false'}
                        />
                        {issues.label && <span className="hof-field-error">{issues.label}</span>}
                    </label>

                    {!isView && (
                        <>
                            <label className="hof-field">
                                <span className="hof-field-label">Source kind</span>
                                <select
                                    className="hof-input"
                                    value={facet.kind === 'view' ? 'taxonomy' : facet.kind}
                                    onChange={(e) => onChange({ kind: e.target.value })}
                                >
                                    {KINDS.filter((k) => k.value !== 'view').map((k) => (
                                        <option key={k.value} value={k.value}>{k.label}</option>
                                    ))}
                                </select>
                            </label>

                            <label className={`hof-field ${issues.source ? 'is-invalid' : ''}`}>
                                <span className="hof-field-label">Source</span>
                                <input
                                    className="hof-input"
                                    type="text"
                                    value={facet.source || ''}
                                    onChange={(e) => onChange({ source: e.target.value })}
                                    placeholder={kindDef.hint}
                                    aria-invalid={issues.source ? 'true' : 'false'}
                                />
                                {issues.source
                                    ? <span className="hof-field-error">{issues.source}</span>
                                    : <span className="hof-field-help">{kindDef.hint}</span>}
                            </label>
                        </>
                    )}

                    <label className="hof-field">
                        <span className="hof-field-label">Display</span>
                        <select
                            className="hof-input"
                            value={facet.display}
                            onChange={(e) => {
                                const next = e.target.value;
                                const patch = { display: next };
                                // Switching to/from a view display auto-syncs
                                // the kind and clears irrelevant fields so
                                // the option saves cleanly.
                                if (VIEW_DISPLAYS.has(next)) {
                                    patch.kind = 'view';
                                    patch.source = '';
                                } else if (facet.kind === 'view') {
                                    patch.kind = 'taxonomy';
                                }
                                onChange(patch);
                            }}
                        >
                            {DISPLAY_GROUPS.map((g) => (
                                <optgroup key={g.group} label={g.group}>
                                    {g.items.map((d) => (
                                        <option key={d.value} value={d.value}>{d.label}</option>
                                    ))}
                                </optgroup>
                            ))}
                        </select>
                        {facet.display === 'swatch' && facet.kind !== 'taxonomy' && (
                            <span className="hof-field-help hof-field-warn">
                                Swatches require a taxonomy source. Falls back to a checkbox list at runtime.
                            </span>
                        )}
                        {facet.display === 'swatch' && facet.kind === 'taxonomy' && (
                            <span className="hof-field-help">
                                Configure swatch image and color per term in the taxonomy admin
                                (<em>Edit term</em> screen for <code>{facet.source || 'your-taxonomy'}</code>).
                            </span>
                        )}
                        {facet.display === 'swiper' && facet.kind === 'taxonomy' && (
                            <span className="hof-field-help">
                                Card visuals reuse the swatch image/color per term. Swipe right to include,
                                left to skip. Multi-select OR within the facet.
                            </span>
                        )}
                        {facet.display === 'swiper' && facet.kind !== 'taxonomy' && (
                            <span className="hof-field-help">
                                Works best with a taxonomy source so cards can show per-term images.
                                Non-taxonomy sources still work but cards will be label-only.
                            </span>
                        )}
                        {facet.display === 'ask' && (
                            <span className="hof-field-help">
                                A conversational, multi-turn facet. Each turn calls Anthropic and
                                returns chips for every constraint the model heard — shoppers can tap
                                ✕ on any chip to correct it. Set the key in <em>Settings → Ask</em>.
                            </span>
                        )}
                        {facet.display === 'visual_dna' && (
                            <span className="hof-field-help">
                                Drop an image, paste a URL, or eyedrop any color on screen — the
                                catalog filters to products in the closest matching color term.
                                Pick the color facet to drive below.
                            </span>
                        )}
                        {facet.display === 'pagination' && (
                            <span className="hof-field-help">
                                Numbered « 1 2 3 » nav for the results region. Reads <code>paged</code>
                                from the URL and respects every other current query arg
                                (filters survive pagination). Click handler is SPA-style — no full
                                page reloads.
                            </span>
                        )}
                    </label>

                    {facet.display === 'ask' && (
                        <label className="hof-field">
                            <span className="hof-field-label">Placeholder text</span>
                            <input
                                className="hof-input"
                                type="text"
                                value={settings.placeholder || ''}
                                onChange={(e) => updateSettings({ placeholder: e.target.value })}
                                placeholder="Describe what you're looking for…"
                            />
                            <span className="hof-field-help">
                                Shown in the input before the shopper types. Hint what kinds of asks
                                work — e.g. <em>"comfy red shoes under $50"</em> or
                                <em>"a gift for my dad's workshop"</em>.
                            </span>
                        </label>
                    )}

                    {MULTI_VALUE_DISPLAYS.has(facet.display) && (
                        <label className="hof-field">
                            <span className="hof-field-label">Match within facet</span>
                            <select
                                className="hof-input"
                                value={settings.match || 'any'}
                                onChange={(e) => updateSettings({ match: e.target.value })}
                            >
                                <option value="any">Any selected value (OR)</option>
                                <option value="all">All selected values (AND)</option>
                            </select>
                            <span className="hof-field-help">
                                <strong>Any</strong> matches items with at least one selected value.
                                {' '}<strong>All</strong> requires every selected value — useful for
                                tags or features where shoppers narrow by stacking choices.
                            </span>
                        </label>
                    )}

                    {facet.display === 'toggle' && (
                        <>
                            <label className="hof-field">
                                <span className="hof-field-label">True value (in the index)</span>
                                <input
                                    className="hof-input"
                                    type="text"
                                    value={settings.true_value || ''}
                                    onChange={(e) => updateSettings({ true_value: e.target.value })}
                                    placeholder="1"
                                />
                                <span className="hof-field-help">
                                    The exact <code>facet_value</code> the index stores for matching
                                    products. Typically <code>1</code> for boolean meta, or e.g.
                                    <code>yes</code>, <code>true</code>, <code>in-stock</code>.
                                </span>
                            </label>
                            <label className="hof-field">
                                <span className="hof-field-label">On label (optional)</span>
                                <input
                                    className="hof-input"
                                    type="text"
                                    value={settings.on_label || ''}
                                    onChange={(e) => updateSettings({ on_label: e.target.value })}
                                    placeholder="On"
                                />
                            </label>
                            <label className="hof-field">
                                <span className="hof-field-label">Off label (optional)</span>
                                <input
                                    className="hof-input"
                                    type="text"
                                    value={settings.off_label || ''}
                                    onChange={(e) => updateSettings({ off_label: e.target.value })}
                                    placeholder="Off"
                                />
                            </label>
                        </>
                    )}

                    {facet.display === 'date_range' && (
                        <span className="hof-field-help">
                            <strong>Note:</strong> the source meta field must already store dates as
                            Unix timestamps in <code>facet_numeric</code>. The Indexer doesn't yet do
                            string-date → epoch conversion; that's a planned alpha follow-up.
                        </span>
                    )}

                    {facet.display === 'visual_dna' && (
                        <label className={`hof-field ${issues['settings.target_facet'] ? 'is-invalid' : ''}`}>
                            <span className="hof-field-label">Target color facet</span>
                            <select
                                className="hof-input"
                                value={settings.target_facet || ''}
                                onChange={(e) => updateSettings({ target_facet: e.target.value })}
                                aria-invalid={issues['settings.target_facet'] ? 'true' : 'false'}
                            >
                                <option value="">— pick a color facet —</option>
                                {colorTargetFacets.map((f) => (
                                    <option key={f.name} value={f.name}>
                                        {f.label || f.name}{' '}({f.display})
                                    </option>
                                ))}
                            </select>
                            {issues['settings.target_facet']
                                ? <span className="hof-field-error">{issues['settings.target_facet']}</span>
                                : colorTargetFacets.length === 0 && (
                                    <span className="hof-field-help hof-field-warn">
                                        No color-bearing facets configured. Add a checkbox, dropdown, or
                                        swatch facet for a color taxonomy first.
                                    </span>
                                )}
                            <span className="hof-field-help">
                                Color terms get their hex from the term's <code>swatch_color</code> meta
                                (same as the swatch facet uses), falling back to a built-in name table
                                for common terms like <code>red</code>, <code>navy</code>, <code>olive</code>.
                            </span>
                        </label>
                    )}

                    {facet.display === 'pagination' && (
                        <>
                            <label className="hof-field">
                                <span className="hof-field-label">Per page (optional)</span>
                                <input
                                    className="hof-input"
                                    type="number"
                                    min="1"
                                    value={settings.per_page || ''}
                                    onChange={(e) => {
                                        const v = e.target.value === '' ? null : Math.max(1, parseInt(e.target.value, 10) || 1);
                                        updateSettings({ per_page: v });
                                    }}
                                    placeholder={`Default: WP "Posts per page" setting`}
                                />
                                <span className="hof-field-help">
                                    Leave blank to use WordPress's <code>posts_per_page</code> option
                                    (Settings → Reading). Override here if this loop should paginate at a
                                    different rate than the rest of the site.
                                </span>
                            </label>

                            <label className="hof-field">
                                <span className="hof-field-label">Neighbors visible</span>
                                <input
                                    className="hof-input"
                                    type="number"
                                    min="0"
                                    max="5"
                                    value={settings.neighbors ?? 2}
                                    onChange={(e) => updateSettings({ neighbors: Math.max(0, Math.min(5, parseInt(e.target.value, 10) || 0)) })}
                                />
                                <span className="hof-field-help">
                                    How many page numbers show on each side of the current page.
                                    Higher = wider nav; lower = more compact. 2 is a sensible default
                                    that fits in most narrow sidebars without wrapping.
                                </span>
                            </label>

                            <label className="hof-field hof-field-inline">
                                <input
                                    type="checkbox"
                                    checked={settings.show_first_last !== false}
                                    onChange={(e) => updateSettings({ show_first_last: e.target.checked })}
                                />
                                <span>Show first/last buttons (« »)</span>
                            </label>

                            <label className="hof-field hof-field-inline">
                                <input
                                    type="checkbox"
                                    checked={settings.show_prev_next !== false}
                                    onChange={(e) => updateSettings({ show_prev_next: e.target.checked })}
                                />
                                <span>Show prev/next buttons (‹ ›)</span>
                            </label>
                        </>
                    )}

                    <div className="hof-editor-actions">
                        <button className="hof-btn hof-btn-danger" onClick={onDelete} type="button">
                            Delete facet
                        </button>
                    </div>
                </section>

                <aside className="hof-editor-preview">
                    <h3 className="hof-editor-subtitle">Preview</h3>
                    <FacetPreview facet={facet} />
                </aside>
            </div>
        </div>
    );
}

function FacetPreview({ facet }) {
    const label = facet.label || facet.name || 'Untitled facet';

    if (facet.display === 'range') {
        return (
            <div className="hof-preview">
                <div className="hof-preview-label">{label}</div>
                <div className="hof-preview-range">
                    <input type="range" disabled />
                    <div className="hof-preview-range-bounds">
                        <span>min</span>
                        <span>max</span>
                    </div>
                </div>
                <p className="hof-preview-note">Bounds populate from index data at runtime.</p>
            </div>
        );
    }

    if (facet.display === 'search') {
        return (
            <div className="hof-preview">
                <div className="hof-preview-label">{label}</div>
                <input className="hof-input" type="search" placeholder="Search…" disabled />
                <p className="hof-preview-note">Matches against the configured source field.</p>
            </div>
        );
    }

    if (facet.display === 'swiper') {
        return (
            <div className="hof-preview">
                <div className="hof-preview-label">{label}</div>
                <div className="hof-preview-swiper">
                    <div className="hof-preview-swiper-card hof-preview-swiper-card-back hof-preview-swiper-card-back-2"></div>
                    <div className="hof-preview-swiper-card hof-preview-swiper-card-back hof-preview-swiper-card-back-1"></div>
                    <div className="hof-preview-swiper-card">
                        <p className="hof-preview-swiper-card-meta">{label} · 1 of 12</p>
                        <p className="hof-preview-swiper-card-label">Top card</p>
                    </div>
                </div>
                <div className="hof-preview-swiper-controls">
                    <span className="hof-preview-swiper-btn hof-preview-swiper-btn-skip">←</span>
                    <span className="hof-preview-swiper-btn hof-preview-swiper-btn-include">→</span>
                </div>
                <p className="hof-preview-note">
                    Right = include, left = skip. Cards reuse the term's swatch image/color.
                </p>
            </div>
        );
    }

    if (facet.display === 'spin_the_wheel') {
        return (
            <div className="hof-preview">
                <div className="hof-preview-label">{label}</div>
                <div className="hof-preview-wheel">
                    <span className="hof-preview-wheel-pointer" />
                    <div className="hof-preview-wheel-dial" />
                    <span className="hof-preview-wheel-spin">Spin</span>
                </div>
                <p className="hof-preview-note">
                    Gamified single-select. Spin lands on a value (or pick one directly).
                </p>
            </div>
        );
    }

    if (facet.display === 'swatch') {
        // Mock tiles sized by descending fake counts so the preview reads as fluid.
        const swatches = [
            { name: 'Red',    weight: 1.0, color: '#e0364f' },
            { name: 'Blue',   weight: 0.7, color: '#1e40af' },
            { name: 'Green',  weight: 0.4, color: '#16a34a' },
            { name: 'Yellow', weight: 0.2, color: '#facc15' },
        ];
        return (
            <div className="hof-preview">
                <div className="hof-preview-label">{label}</div>
                <div className="hof-preview-swatches">
                    {swatches.map((s) => (
                        <div key={s.name} className="hof-preview-swatch">
                            <span
                                className="hof-preview-swatch-visual"
                                style={{
                                    width:  `${24 + s.weight * 56}px`,
                                    height: `${24 + s.weight * 56}px`,
                                    background: s.color,
                                }}
                            />
                            <span>{s.name}</span>
                        </div>
                    ))}
                </div>
                <p className="hof-preview-note">
                    Tile size morphs by count; image/color comes from per-term meta.
                </p>
            </div>
        );
    }


    if (facet.display === 'visual_dna') {
        const settings = (facet.settings && typeof facet.settings === 'object') ? facet.settings : {};
        const ready = !!settings.target_facet;
        return (
            <div className="hof-preview">
                <div className="hof-preview-label">{label}</div>
                <div className="hof-preview-visual-drop">
                    <span className="hof-preview-visual-icon" aria-hidden="true">⬇</span>
                    <span>Drop · paste URL · 🎨 pick</span>
                </div>
                <div className="hof-preview-visual-result">
                    <span className="hof-preview-visual-swatch" style={{ background: '#c84a2d' }} aria-hidden="true"></span>
                    <span className="hof-preview-visual-readout">
                        <code>#c84a2d</code>
                        <span className="hof-preview-visual-match">
                            <span className="hof-preview-visual-dot" style={{ background: '#f97316' }} aria-hidden="true"></span>
                            orange
                        </span>
                    </span>
                </div>
                <p className="hof-preview-note">
                    {ready
                        ? `Drives the "${settings.target_facet}" facet by snapping to its nearest term in LAB ΔE.`
                        : 'Pick a target color facet to wire this up.'}
                </p>
            </div>
        );
    }

    if (facet.display === 'ask') {
        const settings = (facet.settings && typeof facet.settings === 'object') ? facet.settings : {};
        return (
            <div className="hof-preview">
                <div className="hof-preview-label">{label}</div>
                <div className="hof-preview-ask-input">
                    <span className="hof-preview-ask-icon" aria-hidden="true">✦</span>
                    <span className="hof-preview-ask-placeholder">
                        {settings.placeholder || 'Describe what you\'re looking for…'}
                    </span>
                    <span className="hof-preview-ask-send" aria-hidden="true">▶</span>
                </div>
                <div className="hof-preview-ask-heard">
                    <span className="hof-preview-ask-heard-label">I heard:</span>
                    <span className="hof-preview-ask-chip">color: red <em>×</em></span>
                    <span className="hof-preview-ask-chip">price: ≤50 <em>×</em></span>
                </div>
                <p className="hof-preview-note">
                    Conversational. Each chip is removable — taps update both the filter and the
                    model's next-turn context. Configure the key in Settings → Ask.
                </p>
            </div>
        );
    }

    if (facet.display === 'pagination') {
        const settings = (facet.settings && typeof facet.settings === 'object') ? facet.settings : {};
        const showFL = settings.show_first_last !== false;
        const showPN = settings.show_prev_next !== false;
        return (
            <div className="hof-preview">
                <div className="hof-preview-label">{label}</div>
                <div className="hof-preview-pagination">
                    {showFL && <span className="hof-preview-page">«</span>}
                    {showPN && <span className="hof-preview-page">‹</span>}
                    <span className="hof-preview-page">1</span>
                    <span className="hof-preview-page hof-preview-page-current">2</span>
                    <span className="hof-preview-page">3</span>
                    <span className="hof-preview-page-gap">…</span>
                    <span className="hof-preview-page">12</span>
                    {showPN && <span className="hof-preview-page">›</span>}
                    {showFL && <span className="hof-preview-page">»</span>}
                </div>
                <p className="hof-preview-note">
                    Shows on the live site when results span more than one page. Click a number to
                    jump — filters survive the page change.
                </p>
            </div>
        );
    }

    const stub = ['Option A', 'Option B', 'Option C'];
    return (
        <div className="hof-preview">
            <div className="hof-preview-label">{label}</div>
            <ul className="hof-preview-list">
                {stub.map((v) => (
                    <li key={v}>
                        <label>
                            <input type="checkbox" disabled />
                            <span>{v}</span>
                            <span className="hof-preview-count">(0)</span>
                        </label>
                    </li>
                ))}
            </ul>
            <p className="hof-preview-note">Real options + counts populate once the indexer has run.</p>
        </div>
    );
}
