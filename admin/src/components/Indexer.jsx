import { useEffect, useState } from 'react';
import {
    IconAlertCircle,
    IconCheck,
    IconDatabase,
    IconRefresh,
} from '@tabler/icons-react';
import { reindex, getReindexStatus } from '../api.js';

export default function Indexer() {
    const [stats, setStats] = useState(null);       // { rows, objects, by_facet }
    const [loading, setLoading] = useState(true);
    const [running, setRunning] = useState(false);
    const [error, setError] = useState(null);
    const [lastRun, setLastRun] = useState(null);   // { indexed, elapsed }

    useEffect(() => {
        let cancelled = false;
        getReindexStatus()
            .then((data) => { if (!cancelled) setStats(data); })
            .catch((e)   => { if (!cancelled) setError(e.message || 'Failed to load index stats.'); })
            .finally(()  => { if (!cancelled) setLoading(false); });
        return () => { cancelled = true; };
    }, []);

    const runReindex = async () => {
        setRunning(true);
        setError(null);
        try {
            const result = await reindex();
            setStats({
                rows:     result.rows,
                objects:  result.objects,
                by_facet: result.by_facet,
            });
            setLastRun({ indexed: result.indexed, elapsed: result.elapsed });
        } catch (e) {
            setError(e.message || 'Reindex failed.');
        } finally {
            setRunning(false);
        }
    };

    return (
        <div className="hof-indexer">
            <header className="hof-view-header">
                <h2 className="hof-view-title">Indexer</h2>
                <button
                    type="button"
                    className="hof-btn hof-btn-coral"
                    onClick={runReindex}
                    disabled={running}
                >
                    <IconRefresh size={15} stroke={2} aria-hidden="true" className={running ? 'hof-spin' : ''} />
                    {running ? 'Rebuilding…' : 'Reindex now'}
                </button>
            </header>

            <p className="hof-indexer-blurb">
                The index is the flat EAV table that powers sub-50ms multi-facet filtering. Run a full rebuild after
                changing facet configuration or bulk-importing products. Incremental updates happen automatically on
                <code> save_post</code> and <code> set_object_terms</code>.
            </p>

            {error && (
                <div className="hof-indexer-error" role="alert">
                    <IconAlertCircle size={16} stroke={1.75} aria-hidden="true" />
                    <span>{error}</span>
                </div>
            )}

            {lastRun && !error && (
                <div className="hof-indexer-toast" role="status">
                    <IconCheck size={16} stroke={1.75} aria-hidden="true" />
                    <span>
                        Reindexed {fmt(lastRun.indexed)} object{lastRun.indexed === 1 ? '' : 's'} in {lastRun.elapsed.toFixed(2)}s
                        {lastRun.elapsed > 0 ? ` (${fmt(Math.round(lastRun.indexed / lastRun.elapsed))} obj/sec)` : ''}.
                    </span>
                </div>
            )}

            <div className="hof-dash-stats">
                <Stat label="Index rows"        value={loading ? '—' : fmt(stats?.rows)} />
                <Stat label="Distinct objects"  value={loading ? '—' : fmt(stats?.objects)} />
                <Stat label="Facets in index"   value={loading ? '—' : fmt(stats?.by_facet?.length || 0)} />
            </div>

            <p className="hof-eyebrow hof-dash-section-label">Per-facet breakdown</p>

            {loading ? (
                <p className="hof-dash-empty">Loading index stats…</p>
            ) : !stats || (stats.by_facet?.length ?? 0) === 0 ? (
                <p className="hof-dash-empty">
                    Index is empty. Configure facets first, then run a reindex to populate.
                </p>
            ) : (
                <ul className="hof-indexer-facets">
                    {stats.by_facet.map((f) => (
                        <li key={f.name} className="hof-dash-facet">
                            <IconDatabase className="hof-dash-facet-icon" size={18} stroke={1.5} aria-hidden="true" />
                            <div className="hof-dash-facet-text">
                                <p className="hof-dash-facet-name">{f.name}</p>
                                <p className="hof-dash-facet-source">
                                    {fmt(f.rows)} row{f.rows === 1 ? '' : 's'} · {fmt(f.objects)} object{f.objects === 1 ? '' : 's'}
                                </p>
                            </div>
                            <span className="hof-chip">
                                {f.objects > 0 ? (f.rows / f.objects).toFixed(2) : '0'} avg/obj
                            </span>
                        </li>
                    ))}
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
