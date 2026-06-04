# Facet Type: Dropdown

Single-select in a compact widget. Perfect for long lists and tight UIs.

## what it is

A native `<select>` control with an "Any" default option and a match count beside each value. One value at a time — compact where checkboxes would eat real estate.

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
  "kind": "taxonomy",
  "source": "product_brand",
  "display": "dropdown",
  "label": "Brand"
}
```

### options

| Field | Values | Default | What |
|---|---|---|---|
| `kind` | `"taxonomy"` \| `"meta"` \| `"field"` | — | Where the indexed values come from |
| `source` | string | — | The taxonomy slug or meta/field key |

The dropdown is single-select: it renders an "Any" option followed by one option per indexed value, each with a match count. It has no display-specific `settings` in 1.0.0.

## URL state

```text
?hof[brand]=nike
```

Single value. Selecting "Any" clears the param.

## examples

**Single-select brand picker:**

```json
{ "name": "brand", "kind": "taxonomy", "source": "product_brand", "display": "dropdown" }
```

**Single-select country picker (meta source):**

```json
{ "name": "country", "kind": "meta", "source": "country_code", "display": "dropdown" }
```

## see also

- [Checkbox](Facet-Type-Checkbox) — same data shape, larger footprint
- [Radio](Facet-Type-Radio) — single-select with all options always visible
