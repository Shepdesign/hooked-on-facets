# Facet Type: Dropdown

> ✅ **Shipped (experimental).** Single-select; multi-select is a follow-up.

Single- or multi-select in a compact widget. Perfect for long lists and tight UIs.

## what it is

A `<select>`-style control (rendered as a custom combobox for better UX). Supports search-within for long lists. Configurable single or multi mode.

## when to use it

- Long lists (brands, makes/models, locations)
- Space-constrained sidebars
- Mobile-first layouts where checkboxes eat real estate
- Anywhere you'd reach for a `<select>` in plain HTML

## when not to use it

- Short, scannable lists where checkbox visibility helps decisions → use [Checkbox](Facet-Type-Checkbox)
- Visual selections → use [Color Swatch](Facet-Type-Color-Swatch)

## configuration

```json
{
  "name": "brand",
  "type": "dropdown",
  "label": "Brand",
  "source": "taxonomy:product_brand",
  "behavior": {
    "multiple": true,
    "operator": "OR",
    "show_count": true
  },
  "ui": {
    "searchable": true,
    "placeholder": "All brands",
    "clearable": true,
    "max_height": 320
  }
}
```

### options

| Field | Values | Default | What |
|---|---|---|---|
| `behavior.multiple` | bool | `false` | Allow multiple selections |
| `behavior.operator` | `"OR"` \| `"AND"` | `"OR"` | Only used if `multiple: true` |
| `behavior.show_count` | bool | `true` | Show count next to options |
| `ui.searchable` | bool | `true` | Render a search input in the dropdown |
| `ui.placeholder` | string | `""` | Placeholder when nothing selected |
| `ui.clearable` | bool | `true` | Show an "x" to clear selections |
| `ui.max_height` | int | `320` | Max dropdown height in px |

## URL state

**Single:** `?_hof_brand=nike`
**Multi:** `?_hof_brand=nike,adidas,puma`

## planned PHP filters

```php
apply_filters( 'hof_facet_dropdown_choices', $choices, $facet );
apply_filters( 'hof_facet_dropdown_placeholder', $placeholder, $facet );
apply_filters( 'hof_facet_dropdown_searchable', $searchable, $facet );
do_action( 'hof_facet_dropdown_rendered', $facet );
```

## examples

**Searchable brand multi-select:**

```json
{ "name": "brand", "type": "dropdown", "source": "taxonomy:product_brand",
  "behavior": { "multiple": true, "operator": "OR" },
  "ui": { "searchable": true, "placeholder": "All brands" } }
```

**Single-select country picker:**

```json
{ "name": "country", "type": "dropdown", "source": "meta:country_code",
  "behavior": { "multiple": false },
  "ui": { "searchable": true, "clearable": true } }
```

## see also

- [Checkbox](Facet-Type-Checkbox) — same data shape, larger footprint
- [Radio](Facet-Type-Radio) — single-select with all options always visible
