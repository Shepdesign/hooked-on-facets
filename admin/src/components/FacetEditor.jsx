const KINDS = [
    { value: 'taxonomy', label: 'Taxonomy',  hint: 'e.g. product_cat, category, product_tag' },
    { value: 'meta',     label: 'Post meta', hint: 'e.g. _price, _stock_status' },
    { value: 'field',    label: 'Post field', hint: 'e.g. post_title, post_author' },
];

const DISPLAYS = [
    { value: 'checkbox', label: 'Checkbox list' },
    { value: 'range',    label: 'Range slider' },
    { value: 'search',   label: 'Search box' },
    { value: 'swatch',   label: 'Fluid swatches' },
    { value: 'swiper',   label: 'Swipe deck' },
];

const sanitizeSlug = (raw) =>
    String(raw || '').toLowerCase().replace(/[^a-z0-9_-]/g, '');

export default function FacetEditor({ facet, onChange, onDelete }) {
    const kindDef = KINDS.find((k) => k.value === facet.kind) || KINDS[0];

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

                    <label className="hof-field">
                        <span className="hof-field-label">Source kind</span>
                        <select
                            className="hof-input"
                            value={facet.kind}
                            onChange={(e) => onChange({ kind: e.target.value })}
                        >
                            {KINDS.map((k) => (
                                <option key={k.value} value={k.value}>{k.label}</option>
                            ))}
                        </select>
                    </label>

                    <label className="hof-field">
                        <span className="hof-field-label">Source</span>
                        <input
                            className="hof-input"
                            type="text"
                            value={facet.source}
                            onChange={(e) => onChange({ source: e.target.value })}
                            placeholder={kindDef.hint}
                        />
                        <span className="hof-field-help">{kindDef.hint}</span>
                    </label>

                    <label className="hof-field">
                        <span className="hof-field-label">Display</span>
                        <select
                            className="hof-input"
                            value={facet.display}
                            onChange={(e) => onChange({ display: e.target.value })}
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
                    </label>

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
