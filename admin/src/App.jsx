import { useCallback, useState } from 'react';
import { saveFacets } from './api.js';
import Sidebar from './components/Sidebar.jsx';
import FacetEditor from './components/FacetEditor.jsx';
import TokensPanel from './components/TokensPanel.jsx';

const TABS = [
    { id: 'facets', label: 'Facets' },
    { id: 'tokens', label: 'Tokens' },
];

const blankFacet = () => ({
    name: '',
    label: '',
    source: '',
    kind: 'taxonomy',
    display: 'checkbox',
});

export default function App({ bootstrap }) {
    const [tab, setTab] = useState('facets');
    const [facets, setFacets] = useState(() =>
        Array.isArray(bootstrap.facets) ? bootstrap.facets : []
    );
    const [selectedIdx, setSelectedIdx] = useState(facets.length > 0 ? 0 : null);
    const [dirty, setDirty] = useState(false);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState(null);

    const selected = selectedIdx !== null ? facets[selectedIdx] : null;

    const updateSelected = useCallback(
        (patch) => {
            setFacets((prev) =>
                prev.map((f, i) => (i === selectedIdx ? { ...f, ...patch } : f))
            );
            setDirty(true);
        },
        [selectedIdx]
    );

    const addFacet = () => {
        setFacets((prev) => {
            const next = [...prev, blankFacet()];
            setSelectedIdx(next.length - 1);
            return next;
        });
        setDirty(true);
    };

    const deleteSelected = () => {
        if (selectedIdx === null) return;
        const f = facets[selectedIdx];
        if (!window.confirm(`Delete facet "${f.label || f.name || '(unnamed)'}"?`)) return;
        const next = facets.filter((_, i) => i !== selectedIdx);
        setFacets(next);
        setSelectedIdx(next.length > 0 ? Math.max(0, selectedIdx - 1) : null);
        setDirty(true);
    };

    const save = async () => {
        setSaving(true);
        setError(null);
        try {
            const result = await saveFacets(facets);
            setFacets(Array.isArray(result.facets) ? result.facets : []);
            setDirty(false);
        } catch (e) {
            setError(e?.message || 'Save failed');
        } finally {
            setSaving(false);
        }
    };

    return (
        <div className="hof">
            <header className="hof-header">
                <h1 className="hof-title">
                    <svg className="hof-logo-mark" width="32" height="32" viewBox="0 0 72 72" role="img" aria-label="Hooked on Facets">
                        <title>Hooked on Facets</title>
                        <path d="M36 6 L62 21 L36 36 L10 21 Z" fill="#7F77DD" />
                        <path d="M10 21 L10 51 L36 66 L36 36 Z" fill="#3C3489" />
                        <path d="M62 21 L62 51 L36 66 L36 36 Z" fill="#534AB7" />
                        <circle cx="36" cy="11" r="11" fill="#D85A30" stroke="#F1EFE8" strokeWidth="1.5" />
                    </svg>
                    <span className="hof-wordmark">hooked on facets</span>
                </h1>
                <nav className="hof-tabs">
                    {TABS.map((t) => (
                        <button
                            key={t.id}
                            className={`hof-tab ${tab === t.id ? 'is-active' : ''}`}
                            onClick={() => setTab(t.id)}
                            type="button"
                        >
                            {t.label}
                        </button>
                    ))}
                </nav>
                <div className="hof-actions">
                    {error && <span className="hof-error" role="alert">{error}</span>}
                    <button
                        className="hof-btn hof-btn-primary"
                        disabled={!dirty || saving}
                        onClick={save}
                        type="button"
                    >
                        {saving ? 'Saving…' : dirty ? 'Save changes' : 'Saved'}
                    </button>
                </div>
            </header>

            <main className="hof-main">
                {tab === 'facets' ? (
                    <>
                        <Sidebar
                            facets={facets}
                            selectedIdx={selectedIdx}
                            onSelect={setSelectedIdx}
                            onAdd={addFacet}
                        />
                        <section className="hof-content">
                            {selected ? (
                                <FacetEditor
                                    facet={selected}
                                    onChange={updateSelected}
                                    onDelete={deleteSelected}
                                />
                            ) : (
                                <div className="hof-empty">
                                    <p>No facet selected.</p>
                                    <button className="hof-btn" onClick={addFacet} type="button">
                                        Create your first facet
                                    </button>
                                </div>
                            )}
                        </section>
                    </>
                ) : (
                    <TokensPanel tokens={bootstrap.tokens || {}} />
                )}
            </main>
        </div>
    );
}
