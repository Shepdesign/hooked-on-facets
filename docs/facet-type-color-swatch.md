# Facet Type: Color Swatch

> 🚧 **Planned for v0.5 (beta).**

Visual selection via color squares, circles, or images. Built for variation-driven Woo stores.

## what it is

A grid of clickable swatches representing colors (or patterns, fabrics, finishes). Swatches can be solid colors from term meta or image-based for richer visuals.

## when to use it

- Product color variations
- Material/fabric pickers (with image swatches)
- Finish selectors (matte, glossy, brushed)
- Any case where seeing beats reading

## when not to use it

- Non-visual data → use [Checkbox](Facet-Type-Checkbox)
- More than ~30 swatches → use [Dropdown](Facet-Type-Dropdown) with swatch icons
- Single-select needed → set `behavior.multiple: false`

## configuration

```json
{
  "name": "color",
  "type": "color_swatch",
  "label": "Color",
  "source": "taxonomy:pa_color",
  "behavior": {
    "operator": "OR",
    "multiple": true,
    "show_count": false,
    "color_source": "term_meta:swatch_color"
  },
  "ui": {
    "swatch_shape": "circle",
    "swatch_size": 28,
    "show_labels": false,
    "tooltip": true,
    "wrap": true
  }
}
```

### options

| Field | Values | Default | What |
|---|---|---|---|
| `behavior.operator` | `"OR"` \| `"AND"` | `"OR"` | OR if `multiple: true` |
| `behavior.multiple` | bool | `true` | Allow multiple swatches selected |
| `behavior.show_count` | bool | `false` | Show count badge on each swatch |
| `behavior.color_source` | `"term_meta:<key>"` \| `"term_name"` | `"term_meta:swatch_color"` | Where the hex/image comes from |
| `ui.swatch_shape` | `"circle"` \| `"square"` \| `"rounded"` | `"circle"` | Swatch geometry |
| `ui.swatch_size` | int (px) | `28` | Swatch dimension |
| `ui.show_labels` | bool | `false` | Show text label under each swatch |
| `ui.tooltip` | bool | `true` | Hover tooltip with term name |
| `ui.wrap` | bool | `true` | Wrap swatches to multiple rows |

### swatch data

Swatch values come from term meta. The default convention:

```php
// term_meta key: swatch_color
update_term_meta( $term_id, 'swatch_color', '#D85A30' );

// or for image swatches:
update_term_meta( $term_id, 'swatch_image', $attachment_id );
```

HOF will provide an admin UI to set these values per term — no code required.

## URL state

```text
?_hof_color=red,blue,olive
```

Comma-separated slugs (term slugs, not hex values).

## planned PHP filters

```php
apply_filters( 'hof_facet_color_swatch_data', $swatches, $facet );
apply_filters( 'hof_facet_color_swatch_color', $color, $term, $facet );
apply_filters( 'hof_facet_color_swatch_image', $image_url, $term, $facet );
apply_filters( 'hof_facet_color_swatch_label', $label, $term, $facet );
```

## examples

**Standard Woo color attribute:**

```json
{ "name": "color", "type": "color_swatch", "source": "taxonomy:pa_color",
  "behavior": { "operator": "OR", "color_source": "term_meta:swatch_color" },
  "ui": { "swatch_shape": "circle", "tooltip": true } }
```

**Image-based fabric picker with labels:**

```json
{ "name": "fabric", "type": "color_swatch", "source": "taxonomy:pa_fabric",
  "behavior": { "color_source": "term_meta:swatch_image" },
  "ui": { "swatch_shape": "square", "swatch_size": 48, "show_labels": true } }
```

## see also

- [Checkbox](Facet-Type-Checkbox) — non-visual multi-select equivalent
- [[Brand Voice|Brand-Voice]] — color philosophy in HOF's own UI
