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

export default function Dashboard({ facets, productsIndexed, telemetry, onCreateFacet, onOpenBlueprint }) {
    const active = facets.length;
    const hookedLoops = telemetry?.loops?.count ?? 0;
    const totalHits   = telemetry?.loops?.total_hits ?? 0;
    const avgMs       = telemetry?.resolver?.avg_ms;
    const sampleSize  = telemetry?.resolver?.sample_size ?? 0;
    const resolver    = telemetry?.resolver ?? {};
    const analytics   = telemetry?.facets ?? { usage: [], top_values: {}, zero_results: [], total: 0 };

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
                    {hookedLoops > 0
                        ? `${fmtNumber(totalHits)} intercept${totalHits === 1 ? '' : 's'} captured so far across ${hookedLoops} loop${hookedLoops === 1 ? '' : 's'}.`
                        : 'No shortcodes. No template rewrites. We detect your loops on activation and bind the facets you configure.'}
                </p>
            </section>

            <div className="hof-dash-stats">
                <Stat
                    label={sampleSize > 0 ? `Avg query time · last ${sampleSize}` : 'Avg query time'}
                    value={
                        avgMs !== null && avgMs !== undefined
                            ? <><span>{avgMs.toFixed(1)}</span><span className="hof-stat-unit">ms</span></>
                            : <><span>—</span><span className="hof-stat-unit">ms</span></>
                    }
                />
                <Stat label="Products indexed" value={fmtNumber(productsIndexed)} />
                <Stat label="Active facets" value={<><span>{active}</span><span className="hof-stat-unit"> of {active}</span></>} />
            </div>

            <Analytics analytics={analytics} resolver={resolver} facets={facets} />

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

// Usage analytics from the telemetry snapshot: facet/value popularity,
// zero-result combos, latency percentiles, and dead (never-used) facets.
function Analytics({ analytics, resolver, facets }) {
    const usage = analytics.usage || [];
    const zero  = analytics.zero_results || [];
    const hasData = usage.length > 0 || zero.length > 0;

    // Dead facets — configured but never applied by a shopper.
    const usedNames = new Set(usage.map((u) => u.facet));
    const dead = facets
        .filter((f) => f.name && f.source && !usedNames.has(f.name))
        .map((f) => f.label || f.name);

    const maxUsage = usage.reduce((m, u) => Math.max(m, u.count), 0) || 1;

    return (
        <>
            <p className="hof-eyebrow hof-dash-section-label">Analytics</p>

            <div className="hof-dash-stats">
                <Stat label="p50 query" value={msStat(resolver.p50_ms)} />
                <Stat label="p95 query" value={msStat(resolver.p95_ms)} />
                <Stat label="p99 query" value={msStat(resolver.p99_ms)} />
            </div>

            {!hasData ? (
                <p className="hof-dash-empty">
                    No filter activity captured yet. Usage is recorded as shoppers apply facets.
                </p>
            ) : (
                <div className="hof-analytics-grid">
                    <section className="hof-analytics-card">
                        <h3 className="hof-analytics-title">Most-used facets</h3>
                        {usage.length === 0 ? (
                            <p className="hof-analytics-muted">No facet usage yet.</p>
                        ) : (
                            <ul className="hof-analytics-bars">
                                {usage.slice(0, 8).map((u) => (
                                    <li key={u.facet} className="hof-analytics-bar-row">
                                        <span className="hof-analytics-bar-label">{u.facet}</span>
                                        <span className="hof-analytics-bar-track">
                                            <span
                                                className="hof-analytics-bar-fill"
                                                style={{ width: `${Math.round((u.count / maxUsage) * 100)}%` }}
                                            />
                                        </span>
                                        <span className="hof-analytics-bar-count">{fmtNumber(u.count)}</span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>

                    <section className="hof-analytics-card">
                        <h3 className="hof-analytics-title">Filters that find nothing</h3>
                        {zero.length === 0 ? (
                            <p className="hof-analytics-muted">No zero-result filters — nice.</p>
                        ) : (
                            <ul className="hof-analytics-list">
                                {zero.slice(0, 8).map((z) => (
                                    <li key={z.signature} className="hof-analytics-list-row">
                                        <code className="hof-analytics-sig">{z.signature}</code>
                                        <span className="hof-chip">{fmtNumber(z.count)}×</span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                </div>
            )}

            {dead.length > 0 && (
                <p className="hof-analytics-dead">
                    <strong>{dead.length} dead facet{dead.length === 1 ? '' : 's'}</strong> — configured but never
                    used by a shopper: {dead.slice(0, 6).join(', ')}{dead.length > 6 ? '…' : ''}. Consider removing
                    or repositioning {dead.length === 1 ? 'it' : 'them'}.
                </p>
            )}
        </>
    );
}

function msStat(ms) {
    return ms !== null && ms !== undefined
        ? <><span>{Number(ms).toFixed(1)}</span><span className="hof-stat-unit">ms</span></>
        : <><span>—</span><span className="hof-stat-unit">ms</span></>;
}

function fmtNumber(n) {
    if (typeof n !== 'number' || !Number.isFinite(n)) return '—';
    return n.toLocaleString();
}
