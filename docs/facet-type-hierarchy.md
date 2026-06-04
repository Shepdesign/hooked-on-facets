# Facet Type: Hierarchy

Tree-shaped facet for nested taxonomies. Categories with subcategories, locations with regions, etc.

## what it is

A nested, indented list for hierarchical taxonomies, rendered from the taxonomy's own parent/child structure. It requires a taxonomy source — for any other source it falls back to the checkbox renderer at runtime.

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
  "name": "category",
  "kind": "taxonomy",
  "source": "product_cat",
  "display": "hierarchy",
  "label": "Category"
}
```

### options

| Field | Values | Default | What |
|---|---|---|---|
| `kind` | `"taxonomy"` | — | Required; non-taxonomy sources fall back to checkbox |
| `source` | string | — | The hierarchical taxonomy slug (e.g. `product_cat`) |

The tree is built from the taxonomy's own parent/child relationships and indented per level. It has no display-specific `settings` in 1.0.0.

## URL state

```text
?hof[category]=tops,t-shirts
```

Comma-separated term slugs.

## examples

**WooCommerce category tree:**

```json
{ "name": "category", "kind": "taxonomy", "source": "product_cat", "display": "hierarchy" }
```

**Geographic taxonomy:**

```json
{ "name": "location", "kind": "taxonomy", "source": "locations", "display": "hierarchy" }
```

## see also

- [Checkbox](Facet-Type-Checkbox) — for flat taxonomies
- [Dropdown](Facet-Type-Dropdown) — for hierarchy in tight space
