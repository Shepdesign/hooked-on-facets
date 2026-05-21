import { useCallback, useState } from 'react';
import {
    IconArrowsShuffle,
    IconChevronRight,
    IconDatabase,
    IconFilter,
    IconLayoutGrid,
    IconPalette,
    IconSettings,
    IconTools,
} from '@tabler/icons-react';
import { saveFacets } from './api.js';
import { countInvalid } from './validation.js';
import Sidebar from './components/Sidebar.jsx';
import FacetEditor from './components/FacetEditor.jsx';
import TokensPanel from './components/TokensPanel.jsx';
import Dashboard from './components/Dashboard.jsx';
import Blueprint from './components/Blueprint.jsx';
import Indexer from './components/Indexer.jsx';
import QueryLoops from './components/QueryLoops.jsx';
import AiSettings from './components/AiSettings.jsx';

const VIEWS = [
    { id: 'dashboard',  label: 'Dashboard',     section: 'Main',   Icon: IconLayoutGrid },
    { id: 'facets',     label: 'Facets',        section: 'Main',   Icon: IconFilter },
    { id: 'queryloops', label: 'Query loops',   section: 'Main',   Icon: IconArrowsShuffle },
    { id: 'indexer',    label: 'Indexer',       section: 'Main',   Icon: IconDatabase },
    { id: 'blueprint',  label: 'Blueprint',     section: 'Studio', Icon: IconTools },
    { id: 'tokens',     label: 'Design tokens', section: 'Studio', Icon: IconPalette },
    { id: 'settings',   label: 'Settings',      section: 'System', Icon: IconSettings },
];

const SECTION_ORDER = ['Main', 'Studio', 'System'];

const blankFacet = () => ({
    name: '',
    label: '',
    source: '',
    kind: 'taxonomy',
    display: 'checkbox',
});

export default function App({ bootstrap }) {
    const [view, setView] = useState('dashboard');
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
        setView('facets');
    };

    const deleteSelected = () => {
        if (selectedIdx === null) return;
        deleteAt(selectedIdx);
    };

    const deleteAt = (idx) => {
        const f = facets[idx];
        if (!f) return;
        if (!window.confirm(`Delete facet "${f.label || f.name || '(unnamed)'}"?`)) return;
        const next = facets.filter((_, i) => i !== idx);
        setFacets(next);
        if (selectedIdx === idx) {
            setSelectedIdx(next.length > 0 ? Math.max(0, idx - 1) : null);
        } else if (selectedIdx !== null && idx < selectedIdx) {
            setSelectedIdx(selectedIdx - 1);
        }
        setDirty(true);
    };

    const duplicateAt = (idx) => {
        const src = facets[idx];
        if (!src) return;
        const baseName = src.name || 'facet';
        let candidate  = `${baseName}-copy`;
        const taken    = new Set(facets.map((f) => f.name));
        let n = 2;
        while (taken.has(candidate)) {
            candidate = `${baseName}-copy-${n++}`;
        }
        const clone = {
            ...src,
            name:  candidate,
            label: src.label ? `${src.label} (copy)` : '',
            // Deep-clone the settings object so editing the copy doesn't
            // mutate the source.
            settings: src.settings && typeof src.settings === 'object'
                ? JSON.parse(JSON.stringify(src.settings))
                : {},
        };
        const next = [...facets.slice(0, idx + 1), clone, ...facets.slice(idx + 1)];
        setFacets(next);
        setSelectedIdx(idx + 1);
        setDirty(true);
    };

    const reorder = (fromIdx, toIdx) => {
        if (fromIdx === toIdx) return;
        const next = [...facets];
        const [moved] = next.splice(fromIdx, 1);
        next.splice(toIdx, 0, moved);
        setFacets(next);
        // Keep the selected facet selected by tracking its new index.
        if (selectedIdx === fromIdx) {
            setSelectedIdx(toIdx);
        } else if (selectedIdx !== null) {
            // Selection drifted because the moved row passed over it.
            const lo = Math.min(fromIdx, toIdx);
            const hi = Math.max(fromIdx, toIdx);
            if (selectedIdx >= lo && selectedIdx <= hi) {
                if (fromIdx < toIdx) setSelectedIdx(selectedIdx - 1);
                else                  setSelectedIdx(selectedIdx + 1);
            }
        }
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

    // Sync from the Blueprint sandbox: PUT a settings patch onto one facet
    // without going through the dirty-flag flow that the Facets editor uses.
    const saveFacetSettings = async (facetName, settings) => {
        const next = facets.map((f) =>
            f.name === facetName ? { ...f, settings: { ...settings } } : f
        );
        const result = await saveFacets(next);
        const canonical = Array.isArray(result.facets) ? result.facets : next;
        setFacets(canonical);
        return canonical.find((f) => f.name === facetName) || null;
    };

    const currentView = VIEWS.find((v) => v.id === view) || VIEWS[0];

    return (
        <div className="hof">
            <header className="hof-statusbar">
                <span className="hof-crumb hof-crumb-muted">wp-admin</span>
                <IconChevronRight size={12} stroke={1.75} aria-hidden="true" />
                <span className="hof-crumb">hooked-on-facets</span>
                <IconChevronRight size={12} stroke={1.75} aria-hidden="true" />
                <span className="hof-crumb hof-crumb-active">{currentView.label.toLowerCase()}</span>
                <span className="hof-version">v{bootstrap.version || '0.1.0'}</span>
            </header>

            <div className="hof-layout">
                <aside className="hof-nav">
                    <div className="hof-nav-brand">
                        <svg width="58" height="58" viewBox="0 0 72 72" aria-label="Hooked on Facets">
                            <path d="M36 6 L62 21 L36 36 L10 21 Z" fill="#7F77DD" />
                            <path d="M10 21 L10 51 L36 66 L36 36 Z" fill="#3C3489" />
                            <path d="M62 21 L62 51 L36 66 L36 36 Z" fill="#534AB7" />
                            <circle cx="36" cy="11" r="11" fill="#D85A30" stroke="#F1EFE8" strokeWidth="1.5" />
                        </svg>
                        <span className="hof-nav-wordmark">hooked on facets</span>
                    </div>

                    {SECTION_ORDER.map((section) => (
                        <div key={section} className="hof-nav-section">
                            <p className="hof-nav-section-label">{section}</p>
                            {VIEWS.filter((v) => v.section === section).map(({ id, label, Icon }) => (
                                <button
                                    key={id}
                                    type="button"
                                    className={`hof-nav-item ${view === id ? 'is-active' : ''}`}
                                    onClick={() => setView(id)}
                                >
                                    <Icon size={15} stroke={1.5} aria-hidden="true" />
                                    <span>{label}</span>
                                </button>
                            ))}
                        </div>
                    ))}
                </aside>

                <main className="hof-view">
                    {view === 'dashboard' && (
                        <Dashboard
                            facets={facets}
                            productsIndexed={bootstrap.productsIndexed}
                            telemetry={bootstrap.telemetry}
                            onCreateFacet={addFacet}
                            onOpenBlueprint={() => setView('blueprint')}
                        />
                    )}

                    {view === 'facets' && (() => {
                        const invalidCount = countInvalid(facets);
                        const saveLabel = saving
                            ? 'Saving…'
                            : invalidCount > 0
                                ? `Fix ${invalidCount} issue${invalidCount === 1 ? '' : 's'}`
                                : dirty
                                    ? 'Save changes'
                                    : 'Saved';
                        const saveDisabled = saving || !dirty || invalidCount > 0;
                        return (
                        <div className="hof-view-facets">
                            <div className="hof-view-header">
                                <h2 className="hof-view-title">Facets</h2>
                                <div className="hof-view-actions">
                                    {error && <span className="hof-error" role="alert">{error}</span>}
                                    <button
                                        className={`hof-btn hof-btn-primary ${invalidCount > 0 ? 'hof-btn-blocked' : ''}`}
                                        disabled={saveDisabled}
                                        onClick={save}
                                        type="button"
                                        title={invalidCount > 0 ? 'Fix the validation issues before saving' : ''}
                                    >
                                        {saveLabel}
                                    </button>
                                </div>
                            </div>
                            <div className="hof-facets-pane">
                                <Sidebar
                                    facets={facets}
                                    selectedIdx={selectedIdx}
                                    onSelect={setSelectedIdx}
                                    onAdd={addFacet}
                                    onDuplicate={duplicateAt}
                                    onDelete={deleteAt}
                                    onReorder={reorder}
                                />
                                <section className="hof-facets-content">
                                    {selected ? (
                                        <FacetEditor
                                            facet={selected}
                                            onChange={updateSelected}
                                            onDelete={deleteSelected}
                                            allFacets={facets}
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
                            </div>
                        </div>
                        );
                    })()}

                    {view === 'tokens' && <TokensPanel tokens={bootstrap.tokens || {}} />}

                    {view === 'blueprint' && (
                        <Blueprint
                            facets={facets}
                            onBack={() => setView('dashboard')}
                            onSaveSettings={saveFacetSettings}
                        />
                    )}

                    {view === 'indexer' && <Indexer />}

                    {view === 'queryloops' && <QueryLoops />}

                    {view === 'settings' && (
                        <AiSettings bootstrap={bootstrap} />
                    )}
                </main>
            </div>
        </div>
    );
}

function StubView({ label }) {
    return (
        <div className="hof-stub">
            <h2 className="hof-stub-title">{label}</h2>
            <p className="hof-stub-body">
                Coming in a future build. Reach out if you want this prioritized.
            </p>
        </div>
    );
}
