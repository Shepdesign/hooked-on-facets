# Facet Type: Checkbox

The workhorse. Multi-select filtering. The first facet type you'll reach for nine times out of ten.

## what it is

A list of checkbox options. Users tick one or many. Results match items that have *any* (OR) or *all* (AND) of the selected values, depending on configuration.

## when to use it

- Product categories
- Tags
- Product attributes (size, brand, material)
- Multi-value custom meta fields
- Any data where multiple selections are valid

## when not to use it

- More than ~30 options without grouping → use [Dropdown](Facet-Type-Dropdown)
- Single-choice required → use [Radio](Facet-Type-Radio)
- Nested taxonomies → use [Hierarchy](Facet-Type-Hierarchy)
- Visual selections like colors → use [Color Swatch](Facet-Type-Color-Swatch)

## configuration

```json
{
  "name": "category",
  "kind": "taxonomy",
  "source": "product_cat",
  "display": "checkbox",
  "label": "Category",
  "settings": {
    "match": "any"
  }
}
```

### options

| Field | Values | Default | What |
|---|---|---|---|
| `kind` | `"taxonomy"` \| `"meta"` \| `"field"` | — | Where the indexed values come from |
| `source` | string | — | The taxonomy slug or meta/field key (e.g. `product_cat`) |
| `settings.match` | `"any"` \| `"all"` | `"any"` | `any` = OR within the facet (match at least one selected value); `all` = AND (item must carry every selected value) |

Counts are always rendered next to each option, and refresh based on the *other* active facets (drill-down counts share the resolver's subquery). There are no separate `show_count` / `show_zero` / sort / collapse settings in 1.0.0.

## frontend behavior

- Each click toggles the option and triggers a re-query against the index
- The URL updates so the filter state is shareable and survives reload
- Counts refresh based on the *other* active facets (cross-facet awareness)

## URL state

```text
?hof[category]=shirts,pants,shoes
```

Comma-separated term slugs. The `any`/`all` match mode lives in the facet config, not the URL.

## examples

**Multi-select product categories (OR within facet):**

```json
{ "name": "category", "kind": "taxonomy", "source": "product_cat",
  "display": "checkbox", "settings": { "match": "any" } }
```

**All-must-match attribute filter (e.g. "shirts that are *both* organic *and* cotton"):**

```json
{ "name": "features", "kind": "taxonomy", "source": "pa_features",
  "display": "checkbox", "settings": { "match": "all" } }
```

**Meta-sourced checkbox:**

```json
{ "name": "brand", "kind": "meta", "source": "_brand", "display": "checkbox" }
```

## see also

- [Radio](Facet-Type-Radio) — single-select equivalent
- [Hierarchy](Facet-Type-Hierarchy) — for nested taxonomies
- [Dropdown](Facet-Type-Dropdown) — same multi-select, different UI
