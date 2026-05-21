import { useEffect, useState } from 'react';
import {
    IconArchive,
    IconArrowsShuffle,
    IconCategory,
    IconCheck,
    IconHome,
    IconQuestionMark,
    IconRotate,
    IconSearch,
    IconTrash,
} from '@tabler/icons-react';
import { getTelemetry, resetTelemetry } from '../api.js';

// Visual + semantic chip for each intercept type. Kept aligned with the
// signature scheme QueryHook::loop_signature() emits server-side.
const TYPE_META = {
    archive: { label: 'Post type archive', Icon: IconArchive },
    tax:     { label: 'Taxonomy',          Icon: IconCategory },
    search:  { label: 'Search',            Icon: IconSearch },
    home:    { label: 'Home',              Icon: IconHome },
    main:    { label: 'Main query',        Icon: IconArrowsShuffle },
    opt_in:  { label: 'Opt-in (block / shortcode)', Icon: IconCheck },
    unknown: { label: 'Unknown',           Icon: IconQuestionMark },
};

export default function QueryLoops() {
    const [telemetry, setTelemetry] = useState(null);
    const [loading, setLoading]     = useState(true);
    const [error, setError]         = useState(null);
    const [resetting, setResetting] = useState(false);

    const reload = () => {
        setLoading(true);
        return getTelemetry()
            .then(setTelemetry)
            .catch((e) => setError(e.message || 'Failed to load telemetry.'))
            .finally(() => setLoading(false));
    };

    useEffect(() => { reload(); }, []);

    const reset = async () => {
        if (!window.confirm('Reset all hooked-loop counters? This clears the avg query time samples too.')) return;
        setResetting(true);
        setError(null);
        try {
            const fresh = await resetTelemetry();
            setTelemetry(fresh);
        } catch (e) {
            setError(e.message || 'Reset failed.');
        } finally {
            setResetting(false);
        }
    };

    const signatures = telemetry?.loops?.signatures || [];
    const distinct   = telemetry?.loops?.count ?? 0;
    const totalHits  = telemetry?.loops?.total_hits ?? 0;
    const avgMs      = telemetry?.resolver?.avg_ms;

    return (
        <div className="hof-loops">
            <header className="hof-view-header">
                <h2 className="hof-view-title">Query loops</h2>
                <div className="hof-view-actions">
                    <button
                        type="button"
                        className="hof-btn hof-btn-outline"
                        onClick={reload}
                        disabled={loading || resetting}
                        title="Reload"
                    >
                        <IconRotate size={14} stroke={1.75} aria-hidden="true" className={loading ? 'hof-spin' : ''} />
                        Reload
                    </button>
                    <button
                        type="button"
                        className="hof-btn hof-btn-danger"
                        onClick={reset}
                        disabled={resetting || distinct === 0}
                    >
                        <IconTrash size={14} stroke={1.75} aria-hidden="true" />
                        {resetting ? 'Resetting…' : 'Reset counters'}
                    </button>
                </div>
            </header>

            <p className="hof-indexer-blurb">
                Every WP_Query loop the Auto-Hook Engine intercepts is captured here by intercept type and post type.
                Counts increment on every server-rendered page that the engine binds — visit a shop archive with
                <code> ?hof[…]=…</code> params to populate this list.
            </p>

            {error && (
                <div className="hof-indexer-error" role="alert">
                    <span>{error}</span>
                </div>
            )}

            <div className="hof-dash-stats">
                <Stat label="Distinct loops"  value={fmt(distinct)} />
                <Stat label="Total intercepts" value={fmt(totalHits)} />
                <Stat
                    label="Avg query time"
                    value={
                        avgMs !== null && avgMs !== undefined
                            ? <><span>{avgMs.toFixed(1)}</span><span className="hof-stat-unit">ms</span></>
                            : <><span>—</span><span className="hof-stat-unit">ms</span></>
                    }
                />
            </div>

            <p className="hof-eyebrow hof-dash-section-label">Hooked loops</p>

            {loading && signatures.length === 0 ? (
                <p className="hof-dash-empty">Loading telemetry…</p>
            ) : signatures.length === 0 ? (
                <p className="hof-dash-empty">
                    No intercepts captured yet. The engine records a loop the first time it binds facets to a query.
                </p>
            ) : (
                <ul className="hof-loops-list">
                    {signatures.map((s) => {
                        const meta = TYPE_META[s.type] || TYPE_META.unknown;
                        const Icon = meta.Icon;
                        return (
                            <li key={s.signature} className="hof-dash-facet">
                                <Icon className="hof-dash-facet-icon" size={18} stroke={1.5} aria-hidden="true" />
                                <div className="hof-dash-facet-text">
                                    <p className="hof-dash-facet-name">
                                        <code className="hof-loops-sig">{s.signature}</code>
                                    </p>
                                    <p className="hof-dash-facet-source">
                                        {meta.label} · post_type: <code>{s.post_type}</code>
                                        {s.first ? ` · first seen ${ago(s.first)}` : ''}
                                        {s.last && s.last !== s.first ? ` · last ${ago(s.last)}` : ''}
                                    </p>
                                </div>
                                <span className="hof-chip">
                                    {fmt(s.count)} hit{s.count === 1 ? '' : 's'}
                                </span>
                            </li>
                        );
                    })}
                </ul>
            )}
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

function fmt(n) {
    if (typeof n !== 'number' || !Number.isFinite(n)) return '—';
    return n.toLocaleString();
}

function ago(ts) {
    const seconds = Math.floor(Date.now() / 1000 - ts);
    if (seconds < 5)     return 'just now';
    if (seconds < 60)    return `${seconds}s ago`;
    const mins = Math.floor(seconds / 60);
    if (mins < 60)       return `${mins}m ago`;
    const hours = Math.floor(mins / 60);
    if (hours < 24)      return `${hours}h ago`;
    const days = Math.floor(hours / 24);
    if (days < 30)       return `${days}d ago`;
    const months = Math.floor(days / 30);
    return `${months}mo ago`;
}
