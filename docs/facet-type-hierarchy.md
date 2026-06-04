# Facet Type: Hierarchy

> ✅ **Shipped (experimental).**

Tree-shaped facet for nested taxonomies. Categories with subcategories, locations with regions, etc.

## what it is

A nested, indented list (or progressive disclosure pattern) for hierarchical taxonomies. Selecting a parent can optionally include all descendants. Expansion state syncs to URL.

## when to use it

- Multi-level product categories (Clothing → Tops → T-Shirts)
- Location taxonomies (Country → State → City)
- Knowledge bases with topic trees
- Any taxonomy where parent/child relationships are meaningful

## when not to use it

- Flat taxonomies → use [Checkbox](Facet-Type-Checkbox)
- Single-select hierarchy → use [Dropdown](Facet-Type-Dropdown) with grouped options
- Trees deeper than ~4 levels → consider drill-down navigation outside the facet

## configuration

```json
{
  "name": "category_tree",
  "type": "hierarchy",
  "label": "Category",
  "source": "taxonomy:product_cat",
  "behavior": {
    "operator": "OR",
    "include_descendants": true,
    "show_count": true,
    "selection_mode": "leaf_or_branch"
  },
  "ui": {
    "expanded_by_default": false,
    "max_depth": 4,
    "indent_size": 20,
    "expand_on_select": true
  }
}
```

### options

| Field | Values | Default | What |
|---|---|---|---|
| `behavior.operator` | `"OR"` \| `"AND"` | `"OR"` | Match any vs. match all selections |
| `behavior.include_descendants` | bool | `true` | Selecting parent matches all children |
| `behavior.show_count` | bool | `true` | Show count per node |
| `behavior.selection_mode` | `"leaf_only"` \| `"branch_only"` \| `"leaf_or_branch"` | `"leaf_or_branch"` | What level(s) can be selected |
| `ui.expanded_by_default` | bool \| `"selected_only"` | `false` | Initial expansion state |
| `ui.max_depth` | int | `null` | Don't render below this depth |
| `ui.indent_size` | int (px) | `20` | Indentation per level |
| `ui.expand_on_select` | bool | `true` | Expand a node when selected |

## URL state

```text
?_hof_category_tree=15,42
```

Term IDs (or slugs, configurable). Descendant inclusion is config-driven, not URL-driven.

## planned PHP filters

```php
apply_filters( 'hof_facet_hierarchy_tree', $tree, $facet );
apply_filters( 'hof_facet_hierarchy_max_depth', $depth, $facet );
apply_filters( 'hof_facet_hierarchy_node_label', $label, $term, $facet );
apply_filters( 'hof_facet_hierarchy_include_descendants', $include, $facet );
do_action( 'hof_facet_hierarchy_node_rendered', $term, $facet );
```

## examples

**WooCommerce category tree, all-descendants:**

```json
{ "name": "category", "type": "hierarchy", "source": "taxonomy:product_cat",
  "behavior": { "include_descendants": true, "operator": "OR" },
  "ui": { "expanded_by_default": "selected_only" } }
```

**Geographic taxonomy, leaf-only selection:**

```json
{ "name": "location", "type": "hierarchy", "source": "taxonomy:locations",
  "behavior": { "selection_mode": "leaf_only" },
  "ui": { "max_depth": 3 } }
```

## see also

- [Checkbox](Facet-Type-Checkbox) — for flat taxonomies
- [Dropdown](Facet-Type-Dropdown) — for hierarchy in tight space
