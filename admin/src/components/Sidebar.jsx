import { useState, useRef } from 'react';
import {
    IconGripVertical,
    IconCopy,
    IconX,
    IconAlertTriangle,
} from '@tabler/icons-react';
import { validateFacet } from '../validation.js';

// Pointer-based drag-to-reorder. Native HTML5 DnD is flaky here — click
// targets inside the draggable row race with `dragstart`. Pointer events
// give us cleaner control and let the row physics be FLIP-style.
//
// Drag protocol:
//   pointerdown on .hof-sidebar-grip → captures pointer, tracks dragIdx +
//   hoverIdx in component state. Other rows translate to make space.
//   On pointerup, commit the new order via onReorder(fromIdx, toIdx).
export default function Sidebar({
    facets,
    selectedIdx,
    onSelect,
    onAdd,
    onDuplicate,
    onDelete,
    onReorder,
}) {
    const [dragIdx, setDragIdx]   = useState(null);
    const [hoverIdx, setHoverIdx] = useState(null);
    const listRef = useRef(null);
    const rowHeightRef = useRef(0);

    const onGripDown = (e, idx) => {
        if (e.pointerType === 'mouse' && e.button !== 0) return;
        e.preventDefault();

        // Measure once per gesture; rows are uniform.
        const row = e.currentTarget.closest('.hof-sidebar-item');
        rowHeightRef.current = row ? row.offsetHeight + 4 : 36;

        setDragIdx(idx);
        setHoverIdx(idx);
        e.currentTarget.setPointerCapture(e.pointerId);
    };

    const onGripMove = (e) => {
        if (dragIdx === null || !listRef.current) return;
        const rect = listRef.current.getBoundingClientRect();
        const y    = e.clientY - rect.top;
        const idx  = Math.max(0, Math.min(facets.length - 1, Math.floor(y / rowHeightRef.current)));
        if (idx !== hoverIdx) setHoverIdx(idx);
    };

    const onGripUp = (e) => {
        if (dragIdx === null) return;
        try { e.currentTarget.releasePointerCapture?.(e.pointerId); } catch {}
        if (hoverIdx !== null && hoverIdx !== dragIdx) {
            onReorder(dragIdx, hoverIdx);
        }
        setDragIdx(null);
        setHoverIdx(null);
    };

    const rowTransform = (idx) => {
        if (dragIdx === null || hoverIdx === null) return '';
        if (idx === dragIdx) return '';
        if (dragIdx < hoverIdx && idx > dragIdx && idx <= hoverIdx) {
            return `translateY(-${rowHeightRef.current}px)`;
        }
        if (dragIdx > hoverIdx && idx >= hoverIdx && idx < dragIdx) {
            return `translateY(${rowHeightRef.current}px)`;
        }
        return '';
    };

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
                <ul className="hof-sidebar-list" ref={listRef}>
                    {facets.map((f, i) => {
                        const issues  = validateFacet(f, facets);
                        const invalid = Object.keys(issues).length > 0;
                        const issueSummary = invalid
                            ? Object.values(issues).join(' · ')
                            : '';

                        return (
                            <li
                                key={`row-${i}`}
                                className={[
                                    'hof-sidebar-item',
                                    i === selectedIdx ? 'is-active' : '',
                                    i === dragIdx ? 'is-dragging' : '',
                                    invalid ? 'is-invalid' : '',
                                ].filter(Boolean).join(' ')}
                                style={{
                                    transform: rowTransform(i),
                                    transition: dragIdx === null ? 'transform 160ms ease' : 'none',
                                }}
                            >
                                <button
                                    type="button"
                                    className="hof-sidebar-grip"
                                    title="Drag to reorder"
                                    aria-label="Drag to reorder"
                                    onPointerDown={(e) => onGripDown(e, i)}
                                    onPointerMove={onGripMove}
                                    onPointerUp={onGripUp}
                                    onPointerCancel={onGripUp}
                                >
                                    <IconGripVertical size={14} stroke={1.75} aria-hidden="true" />
                                </button>

                                <button
                                    className="hof-sidebar-row-main"
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

                                {invalid && (
                                    <span
                                        className="hof-sidebar-invalid"
                                        title={issueSummary}
                                        aria-label={`Invalid: ${issueSummary}`}
                                    >
                                        <IconAlertTriangle size={13} stroke={1.75} aria-hidden="true" />
                                    </span>
                                )}

                                <div className="hof-sidebar-row-actions">
                                    <button
                                        type="button"
                                        className="hof-sidebar-action"
                                        title="Duplicate facet"
                                        aria-label="Duplicate facet"
                                        onClick={() => onDuplicate(i)}
                                    >
                                        <IconCopy size={13} stroke={1.75} aria-hidden="true" />
                                    </button>
                                    <button
                                        type="button"
                                        className="hof-sidebar-action hof-sidebar-action-danger"
                                        title="Delete facet"
                                        aria-label="Delete facet"
                                        onClick={() => onDelete(i)}
                                    >
                                        <IconX size={13} stroke={1.75} aria-hidden="true" />
                                    </button>
                                </div>
                            </li>
                        );
                    })}
                </ul>
            )}
        </aside>
    );
}
