# Facet Type: Range

A min/max numeric range filter. Price, weight, distance, anything continuous.

## what it is

Two `<input type="number">` controls defining a min and max, with the data-derived bounds shown as placeholders/hints. The resolver matches with a numeric `BETWEEN` against the indexed value. The display slug is `range`.

## when to use it

- Price filtering
- Weight, dimensions
- Numeric custom meta (rating thresholds, duration, distance)
- Anything continuous and bounded

## when not to use it

- Discrete numeric values with few options → use [Checkbox](Facet-Type-Checkbox) or [Radio](Facet-Type-Radio)
- Dates → use [Date Range](Facet-Type-Date-Range)
- Open-ended values (no sensible max) → use [Search](Facet-Type-Search) with a numeric parser

## configuration

```json
{
  "name": "price",
  "kind": "meta",
  "source": "_price",
  "display": "range",
  "label": "Price"
}
```

### options

| Field | Values | Default | What |
|---|---|---|---|
| `kind` | `"meta"` \| `"field"` | — | Where the numeric value comes from |
| `source` | string | — | The meta/field key holding the number |

The min/max bounds are computed from the indexed values and shown as input hints — they are not configured. The facet has no display-specific `settings` in 1.0.0.

## URL state

```text
?hof[price][min]=10&hof[price][max]=200
```

Either side may be omitted for an open-ended range (e.g. only `[max]` for "up to 200", or only `[min]` for "100 and up").

## examples

**WooCommerce price range:**

```json
{ "name": "price", "kind": "meta", "source": "_price", "display": "range" }
```

**Rating range (field source):**

```json
{ "name": "rating", "kind": "field", "source": "average_rating", "display": "range" }
```

## see also

- [Date Range](Facet-Type-Date-Range) — for temporal ranges
- [Toggle](Facet-Type-Toggle) — for boolean numeric thresholds (e.g. "on sale")
