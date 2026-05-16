import {
    IconArrowsShuffle,
    IconCirclesRelation,
    IconFilter,
    IconPlus,
    IconRuler2,
    IconSparkles,
    IconTarget,
    IconTools,
} from '@tabler/icons-react';

const DISPLAY_CHIPS = {
    checkbox: 'Checkbox list',
    range:    'Range slider',
    search:   'Search box',
    swatch:   'Fluid swatches',
    swiper:   'Swipe deck',
    venn:     'Venn matrix',
};

const FACET_ICONS = {
    checkbox: IconFilter,
    range:    IconRuler2,
    search:   IconSparkles,
    swatch:   IconTarget,
    swiper:   IconArrowsShuffle,
    venn:     IconCirclesRelation,
};

export default function Dashboard({ facets, productsIndexed, onCreateFacet, onOpenBlueprint }) {
    const active = facets.length;
    // Placeholder — real query-loop detection lives in QueryHook; not surfaced yet.
    const hookedLoops = 0;

    return (
        <div className="hof-dash">
            <section className="hof-dash-hero">
                <div className="hof-eyebrow">
                    <span className="hof-live-dot" aria-hidden="true"></span>
                    <span>Auto-Hook Engine · Live</span>
                </div>
                <h1 className="hof-dash-headline">
                    {hookedLoops > 0
                        ? `Hooking ${hookedLoops} query loop${hookedLoops === 1 ? '' : 's'} automatically.`
                        : 'Ready to hook query loops the moment you drop a facet.'}
                </h1>
                <p className="hof-dash-sub">
                    No shortcodes. No template rewrites. We detect your loops on activation and bind the facets you configure.
                </p>
            </section>

            <div className="hof-dash-stats">
                <Stat label="Avg query time" value={<><span>—</span><span className="hof-stat-unit">ms</span></>} />
                <Stat label="Products indexed" value={fmtNumber(productsIndexed)} />
                <Stat label="Active facets" value={<><span>{active}</span><span className="hof-stat-unit"> of {active}</span></>} />
            </div>

            <p className="hof-eyebrow hof-dash-section-label">Your facets</p>

            {facets.length === 0 ? (
                <p className="hof-dash-empty">No facets configured yet. Create one to start filtering.</p>
            ) : (
                <ul className="hof-dash-facets">
                    {facets.map((f, i) => {
                        const Icon = FACET_ICONS[f.display] || IconFilter;
                        const live = !!(f.name && f.source);
                        return (
                            <li key={i} className="hof-dash-facet" data-hof-draft={live ? undefined : '1'}>
                                <Icon className="hof-dash-facet-icon" size={18} stroke={1.5} aria-hidden="true" />
                                <div className="hof-dash-facet-text">
                                    <p className="hof-dash-facet-name">{f.label || f.name || 'Untitled'}</p>
                                    <p className="hof-dash-facet-source">
                                        {f.kind || 'taxonomy'} · {f.source || 'unset'}
                                    </p>
                                </div>
                                <span className="hof-chip">{DISPLAY_CHIPS[f.display] || f.display}</span>
                                <span className={live ? 'hof-live-dot' : 'hof-live-dot hof-live-dot-muted'} aria-hidden="true"></span>
                            </li>
                        );
                    })}
                </ul>
            )}

            <div className="hof-dash-actions">
                <button type="button" className="hof-btn hof-btn-coral" onClick={onCreateFacet}>
                    <IconPlus size={15} stroke={2} aria-hidden="true" />
                    Create new facet
                </button>
                <button type="button" className="hof-btn hof-btn-outline" onClick={onOpenBlueprint}>
                    <IconTools size={15} stroke={2} aria-hidden="true" />
                    Blueprint sandbox
                </button>
            </div>
        </div>
    );
}

function Stat({ label, value }) {
    return (
        <div className="hof-stat">
            <p className="hof-eyebrow">{label}</p>
            <p className="hof-stat-value">{value}</p>
        </div>
    );
}

function fmtNumber(n) {
    if (typeof n !== 'number' || !Number.isFinite(n)) return '—';
    return n.toLocaleString();
}
