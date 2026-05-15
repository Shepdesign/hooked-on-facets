export default function Sidebar({ facets, selectedIdx, onSelect, onAdd }) {
    return (
        <aside className="hof-sidebar">
            <div className="hof-sidebar-header">
                <span className="hof-sidebar-title">Facets</span>
                <button
                    className="hof-btn hof-btn-ghost"
                    onClick={onAdd}
                    type="button"
                    title="Add facet"
                >
                    + New
                </button>
            </div>

            {facets.length === 0 ? (
                <div className="hof-sidebar-empty">No facets yet.</div>
            ) : (
                <ul className="hof-sidebar-list">
                    {facets.map((f, i) => (
                        <li key={`${f.name || 'unnamed'}-${i}`}>
                            <button
                                className={`hof-sidebar-item ${i === selectedIdx ? 'is-active' : ''}`}
                                onClick={() => onSelect(i)}
                                type="button"
                            >
                                <span className="hof-sidebar-label">
                                    {f.label || f.name || <em>(unnamed)</em>}
                                </span>
                                <span className="hof-sidebar-meta">
                                    {f.display} · {f.kind}
                                </span>
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </aside>
    );
}
