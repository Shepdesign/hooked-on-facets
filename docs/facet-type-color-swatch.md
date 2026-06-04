# Facet Type: Color Swatch

Visual selection via color tiles or image swatches. Built for variation-driven Woo stores.

## what it is

A set of clickable swatch tiles representing colors (or patterns, fabrics, finishes). Swatches can be solid colors from term meta or image-based for richer visuals. The display slug is `swatch`. Tile size scales with the per-term match count (sqrt-weighted) so popular terms read larger. It requires a taxonomy source — for any other source it falls back to the checkbox renderer at runtime.

## when to use it

- Product color variations
- Material/fabric pickers (with image swatches)
- Finish selectors (matte, glossy, brushed)
- Any case where seeing beats reading

## when not to use it

- Non-visual data → use [Checkbox](Facet-Type-Checkbox)
- Non-taxonomy source → swatches require a taxonomy; use [Checkbox](Facet-Type-Checkbox)
- More than ~30 swatches → use [Dropdown](Facet-Type-Dropdown)

## configuration

```json
{
  "name": "color",
  "kind": "taxonomy",
  "source": "pa_color",
  "display": "swatch",
  "label": "Color",
  "settings": {
    "match": "any"
  }
}
```

### options

| Field | Values | Default | What |
|---|---|---|---|
| `kind` | `"taxonomy"` | — | Required; non-taxonomy sources fall back to checkbox |
| `source` | string | — | The taxonomy slug (e.g. `pa_color`) |
| `settings.match` | `"any"` \| `"all"` | `"any"` | `any` = OR within the facet; `all` = AND (item must carry every selected term) |

### swatch data

Each swatch's color or image comes from term meta. Set these on the term-edit screen for the taxonomy (HOF adds the fields there — no code required), or programmatically:

```php
// hex color swatch
update_term_meta( $term_id, '_hof_swatch_color', '#D85A30' );

// or an image swatch (attachment ID)
update_term_meta( $term_id, '_hof_swatch_image', $attachment_id );
```

When neither is set, the tile falls back to a label-only swatch.

## URL state

```text
?hof[color]=red,blue,olive
```

Comma-separated term slugs (not hex values).

## examples

**Standard Woo color attribute:**

```json
{ "name": "color", "kind": "taxonomy", "source": "pa_color",
  "display": "swatch", "settings": { "match": "any" } }
```

**Fabric picker, all-must-match:**

```json
{ "name": "fabric", "kind": "taxonomy", "source": "pa_fabric",
  "display": "swatch", "settings": { "match": "all" } }
```

## see also

- [Checkbox](Facet-Type-Checkbox) — non-visual multi-select equivalent
- [[Brand Voice|Brand-Voice]] — color philosophy in HOF's own UI
