# Facet Type: Checkbox

> 🚧 **Planned for v0.1 (alpha).** This page is the spec; implementation lands with the alpha scaffold.

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
  "name": "product_category",
  "type": "checkbox",
  "label": "Category",
  "source": "taxonomy:product_cat",
  "behavior": {
    "operator": "OR",
    "show_count": true,
    "show_zero": false,
    "include_children": false
  },
  "ui": {
    "max_visible": 10,
    "collapsible": true,
    "search_within": true,
    "sort": "count_desc"
  }
}
```

### options

| Field | Values | Default | What |
|---|---|---|---|
| `behavior.operator` | `"OR"` \| `"AND"` | `"OR"` | OR matches *any* selected; AND matches *all* |
| `behavior.show_count` | bool | `true` | Show `(42)` next to each option |
| `behavior.show_zero` | bool | `false` | Show options with no matches (greyed out) |
| `behavior.include_children` | bool | `false` | For taxonomies, include descendant terms |
| `ui.max_visible` | int \| `null` | `10` | Collapse list after N items; `null` = show all |
| `ui.collapsible` | bool | `true` | "Show more / less" toggle |
| `ui.search_within` | bool | `false` | Render a search box inside the facet |
| `ui.sort` | `count_desc` \| `count_asc` \| `name_asc` \| `name_desc` \| `manual` | `count_desc` | Option order |

## frontend behavior

- Each click toggles the option and triggers a debounced (default 200ms) re-query
- The URL updates instantly via `history.replaceState`
- Counts refresh based on the *other* active facets (cross-facet awareness)
- Disabled options (count = 0 with `show_zero: true`) cannot be selected

## URL state

```text
?_hof_product_category=shirts,pants,shoes
```

Comma-separated slugs (or term IDs depending on config). The operator (`OR`/`AND`) lives in the facet config, not the URL.

## planned PHP filters

```php
// Modify the underlying query args before the index is hit
apply_filters( 'hof_facet_checkbox_query_args', $args, $facet );

// Modify the list of choices before rendering
apply_filters( 'hof_facet_checkbox_choices', $choices, $facet );

// Modify a single choice's label
apply_filters( 'hof_facet_checkbox_label', $label, $value, $facet );

// Modify the count rendered next to each option
apply_filters( 'hof_facet_checkbox_count', $count, $value, $facet );

// Fire after a facet renders (server side)
do_action( 'hof_facet_checkbox_rendered', $facet, $output );
```

## examples

**Multi-select product categories with counts:**

```json
{ "name": "category", "type": "checkbox", "source": "taxonomy:product_cat",
  "behavior": { "operator": "OR", "show_count": true } }
```

**All-must-match attribute filter (e.g. "shirts that are *both* organic *and* cotton"):**

```json
{ "name": "attributes", "type": "checkbox", "source": "taxonomy:pa_features",
  "behavior": { "operator": "AND" } }
```

**Long category list with search-within:**

```json
{ "name": "brand", "type": "checkbox", "source": "taxonomy:product_brand",
  "ui": { "max_visible": 8, "search_within": true, "sort": "name_asc" } }
```

## see also

- [Radio](Facet-Type-Radio) — single-select equivalent
- [Hierarchy](Facet-Type-Hierarchy) — for nested taxonomies
- [Dropdown](Facet-Type-Dropdown) — same multi-select, different UI
