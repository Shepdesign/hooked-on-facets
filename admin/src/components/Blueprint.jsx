import { useEffect, useMemo, useState } from 'react';
import {
    IconAlertCircle,
    IconArrowLeft,
    IconArrowUpRight,
    IconCheck,
    IconChevronRight,
    IconCloudUpload,
    IconDeviceDesktop,
    IconEye,
    IconLink,
    IconX,
} from '@tabler/icons-react';
import { applyFilter } from '../api.js';

const VARIANTS    = ['Card', 'Grid', 'Swipe'];
const CARD_SIZES  = ['Small', 'Medium', 'Large'];
const ANIMATIONS  = ['Spring', 'Linear'];

const DEFAULTS = {
    variant:   'Card',
    cardSize:  'Medium',
    deckDepth: 3,
    animation: 'Spring',
};

const DISPLAY_LABELS = {
    checkbox: 'Checkbox',
    range:    'Range slider',
    search:   'Search',
    swatch:   'Fluid swatch',
    swiper:   'Swipe deck',
    venn:     'Venn matrix',
};

// Card dimensions per cardSize — feed both into the inline style and into
// CSS variables so the deck-back shims can offset proportionally.
const CARD_DIM = {
    Small:  { w: 132, h: 168 },
    Medium: { w: 160, h: 190 },
    Large:  { w: 196, h: 230 },
};

export default function Blueprint({ facets, onBack, onSaveSettings }) {
    const editable = useMemo(
        () => facets.filter((f) => f.name && f.source),
        [facets]
    );

    const [selectedName, setSelectedName] = useState(
        () => editable.find((f) => f.display === 'swiper')?.name || editable[0]?.name || null
    );
    const editing = editable.find((f) => f.name === selectedName) || null;

    // Local sandbox state — hydrated from the selected facet's saved settings
    // each time the selection changes. Lets the user explore without affecting
    // the facet until they click Sync.
    const [variant,   setVariant]   = useState(DEFAULTS.variant);
    const [cardSize,  setCardSize]  = useState(DEFAULTS.cardSize);
    const [deckDepth, setDeckDepth] = useState(DEFAULTS.deckDepth);
    const [animation, setAnimation] = useState(DEFAULTS.animation);

    const [resultCount, setResultCount] = useState(null);
    const [syncing, setSyncing]         = useState(false);
    const [toast,   setToast]           = useState(null); // { type, message }

    // Hydrate from facet on selection change.
    useEffect(() => {
        const s = editing?.settings || {};
        setVariant(s.variant   ?? DEFAULTS.variant);
        setCardSize(s.cardSize ?? DEFAULTS.cardSize);
        setDeckDepth(typeof s.deckDepth === 'number' ? s.deckDepth : DEFAULTS.deckDepth);
        setAnimation(s.animation ?? DEFAULTS.animation);
        setToast(null);
    }, [editing?.name]); // eslint-disable-line react-hooks/exhaustive-deps

    // Pull the real total from the resolver — empty filters = full catalog.
    useEffect(() => {
        let cancelled = false;
        applyFilter({}, 1, 1)
            .then((r) => { if (!cancelled) setResultCount(r.total ?? null); })
            .catch(()  => { if (!cancelled) setResultCount(null); });
        return () => { cancelled = true; };
    }, []);

    const saved = editing?.settings || {};
    const dirty = (
        variant   !== (saved.variant   ?? DEFAULTS.variant)   ||
        cardSize  !== (saved.cardSize  ?? DEFAULTS.cardSize)  ||
        deckDepth !== (saved.deckDepth ?? DEFAULTS.deckDepth) ||
        animation !== (saved.animation ?? DEFAULTS.animation)
    );

    const sync = async () => {
        if (!editing) return;
        setSyncing(true);
        setToast(null);
        try {
            await onSaveSettings(editing.name, { variant, cardSize, deckDepth, animation });
            setToast({ type: 'ok', message: `Synced to "${editing.label || editing.name}".` });
        } catch (e) {
            setToast({ type: 'err', message: e?.message || 'Sync failed.' });
        } finally {
            setSyncing(false);
        }
    };

    const dim = CARD_DIM[cardSize] || CARD_DIM.Medium;
    const stageStyle = {
        '--hof-bp-card-w': `${dim.w}px`,
        '--hof-bp-card-h': `${dim.h}px`,
    };

    return (
        <div className="hof-bp">
            <div className="hof-bp-bar">
                <button type="button" className="hof-bp-back" onClick={onBack} aria-label="Back">
                    <IconArrowLeft size={14} stroke={1.75} />
                </button>
                <span className="hof-bp-crumb">Sandbox</span>
                <IconChevronRight size={12} stroke={1.75} className="hof-bp-sep" aria-hidden="true" />
                <span className="hof-bp-crumb hof-bp-crumb-active">Shop archive blueprint</span>
                <div className="hof-bp-bar-actions">
                    <button type="button" className="hof-bp-chip">
                        <IconDeviceDesktop size={13} stroke={1.75} aria-hidden="true" />
                        <span>Desktop</span>
                    </button>
                    <button type="button" className="hof-bp-chip hof-bp-chip-icon" aria-label="Toggle preview">
                        <IconEye size={13} stroke={1.75} aria-hidden="true" />
                    </button>
                    <button type="button" className="hof-bp-deploy">
                        <IconCloudUpload size={13} stroke={1.75} aria-hidden="true" />
                        <span>Deploy</span>
                    </button>
                </div>
            </div>

            <div className="hof-bp-grid">
                <section className="hof-bp-canvas">
                    <span className="hof-bp-results-pill">
                        <IconLink size={11} stroke={1.75} aria-hidden="true" />
                        <span>
                            Bricks · Shop archive ·{' '}
                            {resultCount === null ? '— results' : `${resultCount.toLocaleString()} results`} matching
                        </span>
                    </span>

                    <Stage
                        variant={variant}
                        deckDepth={deckDepth}
                        style={stageStyle}
                        editing={editing}
                    />

                    <div className="hof-bp-response">
                        <p className="hof-eyebrow hof-bp-response-label">Products responding live</p>
                        <div className="hof-bp-response-bars">
                            <span className="hof-bp-response-bar" style={{ opacity: 1 }}></span>
                            <span className="hof-bp-response-bar" style={{ opacity: 0.7 }}></span>
                            <span className="hof-bp-response-bar" style={{ opacity: 0.5 }}></span>
                            <span className="hof-bp-response-bar hof-bp-response-bar-muted"></span>
                            <span className="hof-bp-response-bar hof-bp-response-bar-muted"></span>
                        </div>
                    </div>
                </section>

                <aside className="hof-bp-inspector">
                    <p className="hof-eyebrow">Editing</p>

                    {editable.length > 1 && (
                        <div className="hof-bp-facet-picker" role="tablist" aria-label="Pick facet to edit">
                            {editable.map((f) => (
                                <button
                                    key={f.name}
                                    type="button"
                                    role="tab"
                                    aria-selected={f.name === selectedName}
                                    className={`hof-bp-facet-chip ${f.name === selectedName ? 'is-active' : ''}`}
                                    onClick={() => setSelectedName(f.name)}
                                >
                                    {f.label || f.name}
                                </button>
                            ))}
                        </div>
                    )}

                    <h2 className="hof-bp-inspector-title">{editing?.label || editing?.name || 'No facet'}</h2>
                    <p className="hof-bp-inspector-subtitle">
                        {DISPLAY_LABELS[editing?.display] || 'Checkbox'} facet
                    </p>

                    <Field label="Variant">
                        <Segmented options={VARIANTS} value={variant} onChange={setVariant} />
                    </Field>

                    <Field label="Card size">
                        <Segmented options={CARD_SIZES} value={cardSize} onChange={setCardSize} />
                    </Field>

                    <Field label="Deck depth">
                        <div className="hof-bp-slider">
                            <input
                                type="range"
                                min="1"
                                max="10"
                                value={deckDepth}
                                onChange={(e) => setDeckDepth(Number(e.target.value))}
                                className="hof-bp-slider-input"
                                aria-label="Deck depth"
                            />
                            <span className="hof-bp-slider-value">{deckDepth}</span>
                        </div>
                    </Field>

                    <Field label="Animation">
                        <div className="hof-bp-radio-group">
                            {ANIMATIONS.map((a) => (
                                <label key={a} className={`hof-bp-radio ${animation === a ? 'is-active' : ''}`}>
                                    <input
                                        type="radio"
                                        name="hof-bp-animation"
                                        checked={animation === a}
                                        onChange={() => setAnimation(a)}
                                    />
                                    <span className="hof-bp-radio-dot" aria-hidden="true"></span>
                                    <span>{a}</span>
                                </label>
                            ))}
                        </div>
                    </Field>

                    {toast && (
                        <div className={`hof-bp-toast hof-bp-toast-${toast.type}`} role={toast.type === 'err' ? 'alert' : 'status'}>
                            {toast.type === 'ok'
                                ? <IconCheck size={13} stroke={1.75} aria-hidden="true" />
                                : <IconAlertCircle size={13} stroke={1.75} aria-hidden="true" />
                            }
                            <span>{toast.message}</span>
                        </div>
                    )}

                    <div className="hof-bp-inspector-cta">
                        <button
                            type="button"
                            className="hof-btn hof-btn-primary hof-bp-sync"
                            onClick={sync}
                            disabled={!editing || !dirty || syncing}
                        >
                            <IconArrowUpRight size={14} stroke={1.75} aria-hidden="true" />
                            <span>{syncing ? 'Syncing…' : dirty ? 'Sync to query loop' : 'Synced'}</span>
                        </button>
                    </div>
                </aside>
            </div>
        </div>
    );
}

function Stage({ variant, deckDepth, style, editing }) {
    if (variant === 'Grid') {
        // Show a 2×3 mini-grid that scales the same card visual repeated.
        return (
            <div className="hof-bp-stage" style={style}>
                <div className="hof-bp-grid-stage" aria-label="Grid preview">
                    {[0, 1, 2, 3, 4, 5].map((i) => (
                        <div key={i} className="hof-bp-grid-card">
                            <div className="hof-bp-card-visual"></div>
                            <p className="hof-bp-card-meta">{editing?.label || 'Color'}</p>
                            <p className="hof-bp-card-title">{GRID_LABELS[i]}</p>
                        </div>
                    ))}
                </div>
            </div>
        );
    }

    // Card and Swipe both render a stacked deck — only the depth count and
    // wobble differ. depth = how many cards behind the front (0..9).
    const backCount = Math.max(0, Math.min(9, deckDepth - 1));
    const backs = Array.from({ length: backCount }).map((_, i) => i);

    return (
        <div className="hof-bp-stage" style={style}>
            <button type="button" className="hof-bp-action hof-bp-action-skip" aria-label="Skip">
                <IconX size={18} stroke={1.75} />
            </button>

            <div className={`hof-bp-deck ${variant === 'Swipe' ? 'is-swipe' : ''}`}>
                {backs.map((i) => (
                    <div
                        key={i}
                        className="hof-bp-deck-card hof-bp-deck-back"
                        style={{
                            transform: `translate(${(i + 1) * 4}px, ${(i + 1) * 4}px)`,
                            opacity:   Math.max(0.25, 1 - (i + 1) * 0.18),
                            zIndex:    backs.length - i,
                        }}
                    />
                ))}
                <div className="hof-bp-deck-card hof-bp-deck-top" style={{ zIndex: backs.length + 1 }}>
                    <span className="hof-bp-badge">Editing</span>
                    <div className="hof-bp-card-visual"></div>
                    <p className="hof-bp-card-meta">{editing?.label || 'Color'} · 1 of 12</p>
                    <p className="hof-bp-card-title">Sunset coral</p>
                </div>
            </div>

            <button type="button" className="hof-bp-action hof-bp-action-include" aria-label="Include">
                <IconCheck size={18} stroke={1.75} />
            </button>
        </div>
    );
}

const GRID_LABELS = ['Sunset coral', 'Hook purple', 'Deep ink', 'Cream', 'Slate', 'Spruce'];

function Field({ label, children }) {
    return (
        <div className="hof-bp-field">
            <p className="hof-bp-field-label">{label}</p>
            {children}
        </div>
    );
}

function Segmented({ options, value, onChange }) {
    return (
        <div className="hof-bp-segmented">
            {options.map((opt) => (
                <button
                    key={opt}
                    type="button"
                    className={`hof-bp-segmented-item ${value === opt ? 'is-active' : ''}`}
                    onClick={() => onChange(opt)}
                >
                    {opt}
                </button>
            ))}
        </div>
    );
}
