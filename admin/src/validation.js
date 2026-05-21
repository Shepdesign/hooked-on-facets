// Per-facet validation rules. Returns `{ field: 'reason' }` so callers can
// surface field-level errors AND aggregate counts.
//
// Used by FacetEditor (per-field inline errors), Sidebar (row badge), and
// App.jsx (save-button gating).

const SLUG_RE = /^[a-z][a-z0-9_-]*$/;

// Displays that don't have a source (view facets) — must reference another facet.
const VIEW_DISPLAYS = new Set(['two_d_slider', 'ask', 'visual_dna']);

// Displays Visual DNA can target.
const COLOR_TARGET_DISPLAYS = new Set([
    'checkbox', 'radio', 'dropdown', 'swatch', 'swiper',
]);

export function validateFacet(facet, allFacets) {
    const issues = {};
    if (!facet) return issues;

    // Name: required, slug-safe, unique.
    if (!facet.name || facet.name === '') {
        issues.name = 'Slug required.';
    } else if (!SLUG_RE.test(facet.name)) {
        issues.name = 'Slug must start with a letter and contain only a–z, 0–9, _, -.';
    } else if (Array.isArray(allFacets)) {
        const dupes = allFacets.filter((f) => f.name === facet.name);
        if (dupes.length > 1) issues.name = 'Another facet has the same slug.';
    }

    // Label: required.
    if (!facet.label || facet.label.trim() === '') {
        issues.label = 'Label required.';
    }

    const isView = VIEW_DISPLAYS.has(facet.display);

    // Source / kind:
    if (isView) {
        if (facet.kind !== 'view') {
            issues.kind = 'View-display facets must have kind = view.';
        }
    } else {
        if (!facet.kind) issues.kind = 'Pick a source kind.';
        if (!facet.source || facet.source.trim() === '') {
            issues.source = 'Source required.';
        }
    }

    // Display-specific settings.
    const settings = (facet.settings && typeof facet.settings === 'object' && !Array.isArray(facet.settings))
        ? facet.settings
        : {};

    if (facet.display === 'two_d_slider') {
        if (!settings.x_facet) issues['settings.x_facet'] = 'Pick an X axis facet.';
        if (!settings.y_facet) issues['settings.y_facet'] = 'Pick a Y axis facet.';
        if (settings.x_facet && settings.x_facet === settings.y_facet) {
            issues['settings.y_facet'] = 'X and Y must be different facets.';
        }
    }

    if (facet.display === 'visual_dna') {
        if (!settings.target_facet) {
            issues['settings.target_facet'] = 'Pick a target color facet.';
        } else if (Array.isArray(allFacets)) {
            const target = allFacets.find((f) => f.name === settings.target_facet);
            if (!target) {
                issues['settings.target_facet'] = 'Target facet not found.';
            } else if (!COLOR_TARGET_DISPLAYS.has(target.display)) {
                issues['settings.target_facet'] = 'Target must be a color-bearing display.';
            }
        }
    }

    return issues;
}

// Convenience — number of invalid facets in a list.
export function countInvalid(facets) {
    if (!Array.isArray(facets)) return 0;
    let n = 0;
    for (const f of facets) {
        if (Object.keys(validateFacet(f, facets)).length > 0) n++;
    }
    return n;
}
