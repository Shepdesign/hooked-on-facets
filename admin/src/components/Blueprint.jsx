import { useState } from 'react';
import {
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

// Visual sandbox for now — controls reflect local state but don't yet
// drive a real preview pipeline. Wiring to QueryHook + facet renderer
// happens in a follow-up; this scaffolds the brand-aligned UI shell.

const VARIANTS = ['Card', 'Grid', 'Swipe'];
const CARD_SIZES = ['Small', 'Medium', 'Large'];
const ANIMATIONS = ['Spring', 'Linear'];

export default function Blueprint({ facets, onBack }) {
    const editing = facets.find((f) => f.display === 'swiper') || facets[0] || null;

    const [variant, setVariant] = useState('Card');
    const [cardSize, setCardSize] = useState('Medium');
    const [deckDepth, setDeckDepth] = useState(3);
    const [animation, setAnimation] = useState('Spring');

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
                        <span>Bricks · Shop archive · 217 results matching</span>
                    </span>

                    <div className="hof-bp-stage">
                        <button type="button" className="hof-bp-action hof-bp-action-skip" aria-label="Skip">
                            <IconX size={18} stroke={1.75} />
                        </button>

                        <div className="hof-bp-deck">
                            <div className="hof-bp-deck-card hof-bp-deck-back-2"></div>
                            <div className="hof-bp-deck-card hof-bp-deck-back-1"></div>
                            <div className="hof-bp-deck-card hof-bp-deck-top">
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

                    <div className="hof-bp-response">
                        <p className="hof-eyebrow hof-bp-response-label">Products responding live</p>
                        <div className="hof-bp-response-bars">
                            <span className="hof-bp-bar" style={{ opacity: 1 }}></span>
                            <span className="hof-bp-bar" style={{ opacity: 0.7 }}></span>
                            <span className="hof-bp-bar" style={{ opacity: 0.5 }}></span>
                            <span className="hof-bp-bar hof-bp-bar-muted"></span>
                            <span className="hof-bp-bar hof-bp-bar-muted"></span>
                        </div>
                    </div>
                </section>

                <aside className="hof-bp-inspector">
                    <p className="hof-eyebrow">Editing</p>
                    <h2 className="hof-bp-inspector-title">{editing?.label || 'Color'}</h2>
                    <p className="hof-bp-inspector-subtitle">
                        {displayLabel(editing?.display)} facet
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

                    <div className="hof-bp-inspector-cta">
                        <button type="button" className="hof-btn hof-btn-primary hof-bp-sync">
                            <IconArrowUpRight size={14} stroke={1.75} aria-hidden="true" />
                            <span>Sync to query loop</span>
                        </button>
                    </div>
                </aside>
            </div>
        </div>
    );
}

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

function displayLabel(display) {
    return {
        checkbox: 'Checkbox',
        range:    'Range slider',
        search:   'Search',
        swatch:   'Fluid swatch',
        swiper:   'Swipe deck',
        venn:     'Venn matrix',
    }[display] || 'Checkbox';
}
