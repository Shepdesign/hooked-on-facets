const KINDS = [
    { value: 'taxonomy', label: 'Taxonomy',  hint: 'e.g. product_cat, category, product_tag' },
    { value: 'meta',     label: 'Post meta', hint: 'e.g. _price, _stock_status' },
    { value: 'field',    label: 'Post field', hint: 'e.g. post_title, post_author' },
    { value: 'view',     label: 'View (no source)', hint: 'Orchestrates other facets — no source needed.' },
];

const DISPLAYS = [
    { value: 'checkbox',     label: 'Checkbox list' },
    { value: 'radio',        label: 'Radio (single-select)' },
    { value: 'dropdown',     label: 'Dropdown' },
    { value: 'toggle',       label: 'Toggle' },
    { value: 'hierarchy',    label: 'Hierarchy (nested taxonomy)' },
    { value: 'range',        label: 'Range slider' },
    { value: 'date_range',   label: 'Date range' },
    { value: 'search',       label: 'Search box' },
    { value: 'swatch',       label: 'Fluid swatches' },
    { value: 'swiper',       label: 'Swipe deck' },
    { value: 'two_d_slider', label: '2D slider' },
    { value: 'ask',          label: 'Ask' },
];

// Displays that don't have a source — they orchestrate other facets.
const VIEW_DISPLAYS = new Set(['two_d_slider', 'ask']);

const sanitizeSlug = (raw) =>
    String(raw || '').toLowerCase().replace(/[^a-z0-9_-]/g, '');

export default function FacetEditor({ facet, onChange, onDelete, allFacets = [] }) {
    const kindDef = KINDS.find((k) => k.value === facet.kind) || KINDS[0];
    const isView  = VIEW_DISPLAYS.has(facet.display);
    const rangeFacets = allFacets.filter((f) => f.display === 'range' && f.name !== facet.name);
    const settings = (facet.settings && typeof facet.settings === 'object' && !Array.isArray(facet.settings))
        ? facet.settings
        : {};

    const updateSettings = (patch) => {
        onChange({ settings: { ...settings, ...patch } });
    };

    return (
        <div className="hof-editor">
            <div className="hof-editor-grid">
                <section className="hof-editor-form">
                    <h2 className="hof-editor-title">Edit facet</h2>

                    <label className="hof-field">
                        <span className="hof-field-label">Slug</span>
                        <input
                            className="hof-input"
                            type="text"
                            value={facet.name}
                            onChange={(e) => onChange({ name: sanitizeSlug(e.target.value) })}
                            placeholder="brand"
                        />
                        <span className="hof-field-help">
                            URL-safe identifier used in <code>?hof[slug]=…</code>. Lowercase, hyphens and underscores only.
                        </span>
                    </label>

                    <label className="hof-field">
                        <span className="hof-field-label">Label</span>
                        <input
                            className="hof-input"
                            type="text"
                            value={facet.label}
                            onChange={(e) => onChange({ label: e.target.value })}
                            placeholder="Brand"
                        />
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

                            <label className="hof-field">
                                <span className="hof-field-label">Source</span>
                                <input
                                    className="hof-input"
                                    type="text"
                                    value={facet.source || ''}
                                    onChange={(e) => onChange({ source: e.target.value })}
                                    placeholder={kindDef.hint}
                                />
                                <span className="hof-field-help">{kindDef.hint}</span>
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
                            {DISPLAYS.map((d) => (
                                <option key={d.value} value={d.value}>{d.label}</option>
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
                        {facet.display === 'two_d_slider' && (
                            <span className="hof-field-help">
                                Drag a rectangle on a 2D plane to range-filter two numeric facets at once.
                                Pick the x and y axes below.
                            </span>
                        )}
                        {facet.display === 'ask' && (
                            <span className="hof-field-help">
                                A conversational, multi-turn facet. Each turn calls Anthropic and
                                returns chips for every constraint the model heard — shoppers can tap
                                ✕ on any chip to correct it. Set the key in <em>Settings → Ask</em>.
                            </span>
                        )}
                    </label>

                    {facet.display === 'two_d_slider' && (
                        <>
                            <label className="hof-field">
                                <span className="hof-field-label">X axis (range facet)</span>
                                <select
                                    className="hof-input"
                                    value={settings.x_facet || ''}
                                    onChange={(e) => updateSettings({ x_facet: e.target.value })}
                                >
                                    <option value="">— pick a facet —</option>
                                    {rangeFacets.map((f) => (
                                        <option key={f.name} value={f.name}>
                                            {f.label || f.name}{' '}
                                            <code>({f.name})</code>
                                        </option>
                                    ))}
                                </select>
                                {rangeFacets.length === 0 && (
                                    <span className="hof-field-help hof-field-warn">
                                        No range-display facets configured. Create at least two range
                                        facets first, then come back here.
                                    </span>
                                )}
                            </label>

                            <label className="hof-field">
                                <span className="hof-field-label">Y axis (range facet)</span>
                                <select
                                    className="hof-input"
                                    value={settings.y_facet || ''}
                                    onChange={(e) => updateSettings({ y_facet: e.target.value })}
                                >
                                    <option value="">— pick a facet —</option>
                                    {rangeFacets.map((f) => (
                                        <option key={f.name} value={f.name}>
                                            {f.label || f.name}{' '}
                                            <code>({f.name})</code>
                                        </option>
                                    ))}
                                </select>
                                {settings.x_facet && settings.y_facet && settings.x_facet === settings.y_facet && (
                                    <span className="hof-field-help hof-field-warn">
                                        X and Y must reference different facets.
                                    </span>
                                )}
                            </label>
                        </>
                    )}

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

    if (facet.display === 'two_d_slider') {
        const settings = (facet.settings && typeof facet.settings === 'object') ? facet.settings : {};
        const ready = !!settings.x_facet && !!settings.y_facet && settings.x_facet !== settings.y_facet;
        return (
            <div className="hof-preview">
                <div className="hof-preview-label">{label}</div>
                <div className="hof-preview-2d">
                    <div className="hof-preview-2d-plane">
                        <div className="hof-preview-2d-rect" />
                    </div>
                    <p className="hof-preview-2d-axes">
                        <span>x: <code>{settings.x_facet || '—'}</code></span>
                        <span>y: <code>{settings.y_facet || '—'}</code></span>
                    </p>
                </div>
                <p className="hof-preview-note">
                    {ready
                        ? 'Shoppers drag a rectangle on this plane; both axes update at once.'
                        : 'Pick the x and y range facets to wire this up.'}
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
